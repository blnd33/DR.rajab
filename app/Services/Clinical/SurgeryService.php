<?php

namespace App\Services\Clinical;

use App\Enums\AttachmentKind;
use App\Enums\KitCheckoutStatus;
use App\Enums\Laterality;
use App\Enums\LocationType;
use App\Enums\ScanContext;
use App\Enums\StockStatus;
use App\Enums\SurgeryDoctorRole;
use App\Enums\SurgeryItemSource;
use App\Enums\SurgeryStatus;
use App\Exceptions\Domain\StockRuleViolation;
use App\Models\Attachment;
use App\Models\Branch;
use App\Models\KitCheckout;
use App\Models\Patient;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockUnit;
use App\Models\Surgery;
use App\Models\User;
use App\Notifications\DocumentEvent;
use App\Services\Barcode\ScanResolver;
use App\Services\Barcode\ScanResultKind;
use App\Services\Movement\Notifier;
use App\Services\Numbering\DocumentNumberGenerator;
use App\Services\Permissions\BranchScopeResolver;
use App\Services\Stock\StockMovementService;
use App\Support\Msg;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Surgery logging (Module 10, P3-2 … P3-5). Drafts come from the phone (offline-tolerant,
 * idempotent by client_uuid) or the admin form; completing takes the items out of stock.
 */
class SurgeryService
{
    public const WARN_NOT_IN_BRANCH = 'not_in_branch';

    public const WARN_NOT_IN_KIT = 'not_in_kit';

    public function __construct(
        private readonly StockMovementService $stock,
        private readonly BranchScopeResolver $scope,
        private readonly DocumentNumberGenerator $numbers,
        private readonly PatientService $patients,
        private readonly ScanResolver $scanner,
        private readonly Notifier $notifier,
    ) {}

    /**
     * Create or update a draft from a payload, and complete it when asked and nothing blocks.
     * A replay of a surgery that is no longer a draft returns it unchanged.
     *
     * Payload: client_uuid, branch_id, surgery_date, surgery_type_id, customer_id, patient_id |
     * new_patient{full_name, phone, age, date_of_birth, gender}, laterality, kit_checkout_id,
     * lead_doctor_id, assistant_ids[], notes, override_reason, complete,
     * items[{raw | stock_unit_id | stock_batch_id | product_id + lot, quantity}].
     *
     * @param  array<string, mixed>  $payload
     */
    public function sync(array $payload, User $by): Surgery
    {
        $uuid = (string) ($payload['client_uuid'] ?? '');

        if (! Str::isUuid($uuid)) {
            throw new StockRuleViolation(__('Invalid draft id.'));
        }

        $existing = Surgery::query()->where('client_uuid', $uuid)->first();

        if ($existing !== null && $existing->status !== SurgeryStatus::Draft) {
            return $existing;
        }

        $branch = Branch::query()->findOrFail($payload['branch_id'] ?? $existing?->branch_id);
        $this->authorize($by, 'surgeries.create', $branch->id);

        $surgery = DB::transaction(function () use ($payload, $by, $existing, $branch, $uuid) {
            $surgery = $existing ?? new Surgery([
                'client_uuid' => $uuid,
                'number' => $this->numbers->next('SRG', $branch),
                'created_by' => $by->id,
                'status' => SurgeryStatus::Draft,
            ]);

            $surgery->fill([
                'branch_id' => $branch->id,
                'surgery_date' => $payload['surgery_date'] ?? now()->toDateString(),
                'surgery_type_id' => $payload['surgery_type_id'] ?? null,
                'customer_id' => $payload['customer_id'] ?? null,
                'patient_id' => $this->patientId($payload, $branch, $by, $existing),
                'laterality' => Laterality::tryFrom((string) ($payload['laterality'] ?? '')) ?? Laterality::NotApplicable,
                'kit_checkout_id' => $payload['kit_checkout_id'] ?? null,
                'notes' => $payload['notes'] ?? null,
            ]);
            $surgery->kit_id = $surgery->kit_checkout_id !== null ? KitCheckout::query()->whereKey($surgery->kit_checkout_id)->value('kit_id') : null;
            $surgery->save();

            $this->syncDoctors($surgery, $payload, $by);

            // Draft lines have no stock effect yet: replace them with what the phone sends.
            $surgery->items()->delete();
            $problems = $this->headerProblems($surgery);
            $seen = [];

            foreach ($payload['items'] ?? [] as $line) {
                [$attributes, $problem] = $this->resolveLine($surgery, (array) $line, $payload['override_reason'] ?? null);

                if ($problem !== null) {
                    $problems[] = $problem;
                }

                if ($attributes === null) {
                    continue;
                }

                if (($attributes['stock_unit_id'] ?? null) !== null) {
                    if (isset($seen[$attributes['stock_unit_id']])) {
                        continue; // the same serial scanned twice
                    }
                    $seen[$attributes['stock_unit_id']] = true;
                }

                $surgery->items()->create($attributes);
            }

            if (! $surgery->items()->exists()) {
                $problems[] = __('Add at least one implant or product.');
            }

            $surgery->problems = $problems === [] ? null : array_values(array_unique($problems));
            $surgery->save();

            return $surgery;
        });

        if (! empty($payload['complete']) && $surgery->problems === null) {
            try {
                return $this->complete($surgery, $by, $payload['override_reason'] ?? null);
            } catch (StockRuleViolation $e) {
                // Rolled back: the draft stays, with the reason, to fix online.
                $surgery->forceFill(['problems' => [$e->getMessage()]])->save();
            }
        }

        return $surgery->refresh();
    }

    /**
     * Take everything out of stock in one transaction (rules re-checked under lock).
     */
    public function complete(Surgery $surgery, User $by, ?string $overrideReason = null): Surgery
    {
        $this->authorize($by, 'surgeries.create', $surgery->branch_id);

        $surgery = DB::transaction(function () use ($surgery, $by, $overrideReason) {
            /** @var Surgery $surgery */
            $surgery = Surgery::query()->with(['branch', 'customer', 'items.stockUnit', 'items.stockBatch', 'items.product'])->lockForUpdate()->findOrFail($surgery->id);

            if ($surgery->status !== SurgeryStatus::Draft) {
                throw new StockRuleViolation(__('Surgery :number is already :status.', ['number' => $surgery->number, 'status' => $surgery->status->getLabel()]));
            }

            if (($problem = $this->headerProblems($surgery)[0] ?? null) !== null) {
                throw new StockRuleViolation($problem);
            }

            if ($surgery->items->isEmpty()) {
                throw new StockRuleViolation(__('Add at least one implant or product.'));
            }

            $group = (string) Str::uuid();

            foreach ($surgery->items as $item) {
                $reason = $item->override_reason ?? $overrideReason;

                if ($item->stock_unit_id !== null) {
                    $movement = $this->stock->useUnitInSurgery($item->stockUnit, $surgery->branch, $surgery->customer, $by, $surgery, $group, $reason);
                } else {
                    $movement = $this->stock->useBatchInSurgery($item->stockBatch, $item->quantity, $surgery->branch, $surgery->customer, $by, $surgery, $group, $reason);
                }

                $item->forceFill([
                    'used_at' => now(),
                    'unit_cost' => $movement->unit_cost,
                    'cost_currency' => $movement->cost_currency,
                    'override_reason' => $movement->is_override ? $movement->override_reason : null,
                ])->save();
            }

            $surgery->forceFill([
                'status' => SurgeryStatus::Completed,
                'completed_by' => $by->id,
                'completed_at' => now(),
                'problems' => null,
            ])->save();

            return $surgery;
        });

        if ($surgery->items()->whereNotNull('warnings')->exists()) {
            $this->notifier->toBranch('kits.resolve_unaccounted', $surgery->branch_id, new DocumentEvent(
                'surgeries.warnings',
                Msg::key('Surgery :number was logged with warnings'),
                ['number' => $surgery->number],
                Msg::key('Some items were not in the branch or in the kit of this surgery.'),
                [],
                route('filament.admin.resources.surgeries.view', $surgery),
                'heroicon-o-exclamation-triangle',
            ), $by);
        }

        return $surgery->refresh();
    }

    /**
     * Cancel: a draft simply closes; a completed surgery puts every item back (surgery_reversal).
     */
    public function cancel(Surgery $surgery, string $reason, User $by): Surgery
    {
        if (blank($reason)) {
            throw new StockRuleViolation(__('A reason is required.'), 'reason');
        }

        $surgery->status === SurgeryStatus::Draft && $surgery->created_by === $by->id
            ? $this->authorize($by, 'surgeries.create', $surgery->branch_id)
            : $this->authorize($by, 'surgeries.cancel', $surgery->branch_id);

        return DB::transaction(function () use ($surgery, $reason, $by) {
            /** @var Surgery $surgery */
            $surgery = Surgery::query()->with(['branch', 'liveItems.stockUnit', 'liveItems.stockBatch'])->lockForUpdate()->findOrFail($surgery->id);

            if ($surgery->status === SurgeryStatus::Cancelled) {
                throw new StockRuleViolation(__('Surgery :number is already :status.', ['number' => $surgery->number, 'status' => $surgery->status->getLabel()]));
            }

            if ($surgery->status === SurgeryStatus::Completed) {
                $group = (string) Str::uuid();

                foreach ($surgery->liveItems as $item) {
                    $item->stock_unit_id !== null
                        ? $this->stock->reverseSurgeryUnit($item->stockUnit, $surgery->branch, $by, $surgery, $group, $reason)
                        : $this->stock->reverseSurgeryBatch($item->stockBatch, $item->quantity, $surgery->branch, $by, $surgery, $group, $reason);

                    $item->forceFill(['reversed_at' => now(), 'reversed_by' => $by->id, 'reversal_reason' => $reason])->save();
                }
            }

            $surgery->forceFill([
                'status' => SurgeryStatus::Cancelled,
                'cancelled_by' => $by->id,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ])->save();

            return $surgery;
        });
    }

    /**
     * Photo of the implant stickers (added online, also after completion).
     */
    public function attachPhoto(Surgery $surgery, UploadedFile $photo, User $by): Attachment
    {
        $this->authorize($by, 'surgeries.create', $surgery->branch_id);

        if ($surgery->status === SurgeryStatus::Cancelled) {
            throw new StockRuleViolation(__('Surgery :number is already :status.', ['number' => $surgery->number, 'status' => $surgery->status->getLabel()]));
        }

        $path = $photo->store('surgeries/'.$surgery->id, 'local');

        return Attachment::query()->create([
            'attachable_type' => $surgery->getMorphClass(),
            'attachable_id' => $surgery->id,
            'kind' => AttachmentKind::Photo,
            'disk' => 'local',
            'path' => (string) $path,
            'original_name' => mb_substr($photo->getClientOriginalName(), 0, 191),
            'mime' => $photo->getMimeType(),
            'size_bytes' => (int) $photo->getSize(),
            'uploaded_by' => $by->id,
        ]);
    }

    /**
     * Surgeries the user may see: in their surgeries.view_any branches; without
     * patients.view_all only the ones they operated on or logged (patients stay private).
     *
     * @return Builder<Surgery>
     */
    public function visibleTo(User $user): Builder
    {
        $query = $this->scope->apply(Surgery::query(), $user, 'surgeries.view_any');

        if ($this->scope->hasAny($user, 'patients.view_all')) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q->where('created_by', $user->id)
            ->orWhereHas('doctors', fn ($d) => $d->where('users.id', $user->id)));
    }

    /**
     * Turn one payload line into surgery item attributes, or a problem that keeps it a draft.
     *
     * @param  array<string, mixed>  $line
     * @return array{0: ?array<string, mixed>, 1: ?string}
     */
    public function resolveLine(Surgery $surgery, array $line, ?string $overrideReason = null): array
    {
        $raw = trim((string) ($line['raw'] ?? ''));
        $quantity = max(1, (int) ($line['quantity'] ?? 1));
        $unit = null;
        $product = null;
        $lot = $line['lot'] ?? null;
        $expiry = null;

        if (filled($line['stock_unit_id'] ?? null)) {
            $unit = StockUnit::query()->find($line['stock_unit_id']);
        } elseif (filled($line['stock_batch_id'] ?? null)) {
            $batch = StockBatch::query()->find($line['stock_batch_id']);

            return $batch === null
                ? [null, __('Lot not found.')]
                : $this->batchLine($surgery, $batch, $quantity, $raw, $overrideReason);
        } elseif (filled($line['product_id'] ?? null)) {
            $product = Product::query()->find($line['product_id']);
        } elseif ($raw !== '') {
            $result = $this->scanner->resolve($raw, ScanContext::Surgery, null, $surgery->branch_id);

            if ($result->kind === ScanResultKind::Unit) {
                $unit = $result->unit;
            } elseif ($result->product !== null) {
                $product = $result->product;
                $lot = $result->parsed->lot ?? $lot;
                $expiry = $result->parsed->expiryDate;
            }
        }

        if ($unit !== null) {
            return $this->unitLine($surgery, $unit, $raw, $overrideReason);
        }

        if ($product === null) {
            return [null, __('Code not recognised: :code', ['code' => $raw !== '' ? $raw : '—'])];
        }

        if ($product->isSerialTracked()) {
            return [null, __(':sku is serial-tracked — scan the serial number.', ['sku' => $product->sku])];
        }

        $batch = $this->pickBucket($surgery, $product, $quantity, $lot, $expiry);

        if ($batch === null) {
            return [null, __('No :sku with :qty available for this surgery.', ['sku' => $product->sku, 'qty' => $quantity])];
        }

        return $this->batchLine($surgery, $batch, $quantity, $raw, $overrideReason);
    }

    /**
     * @return array{0: ?array<string, mixed>, 1: ?string}
     */
    private function unitLine(Surgery $surgery, StockUnit $unit, string $raw, ?string $overrideReason): array
    {
        $warnings = [];
        $source = SurgeryItemSource::Shelf;
        $kitId = null;
        $checkoutKit = $this->checkoutKitId($surgery);

        switch ($unit->status) {
            case StockStatus::InStock:
                if ($unit->branch_id !== $surgery->branch_id) {
                    $warnings[] = self::WARN_NOT_IN_BRANCH;
                }
                break;

            case StockStatus::InKit:
            case StockStatus::OutAtHospital:
                $source = SurgeryItemSource::Kit;
                $kitId = $unit->kit_id;
                if ($checkoutKit === null || $unit->kit_id !== $checkoutKit) {
                    $warnings[] = self::WARN_NOT_IN_KIT;
                }
                break;

            case StockStatus::UsedInSurgery:
                return [null, __('Unit :serial was already used in a surgery.', ['serial' => $unit->serial])];

            default:
                return [null, __('Unit :serial is :status and cannot be used.', ['serial' => $unit->serial, 'status' => $unit->status->getLabel()])];
        }

        $problem = $unit->isBlockedByExpiry() && blank($overrideReason)
            ? __(':code is expired — give a reason to use it anyway (needs permission).', ['code' => $unit->serial])
            : null;

        return [[
            'product_id' => $unit->product_id,
            'stock_unit_id' => $unit->id,
            'quantity' => 1,
            'source' => $source,
            'kit_id' => $kitId,
            'warnings' => $warnings === [] ? null : $warnings,
            'override_reason' => $unit->isBlockedByExpiry() ? $overrideReason : null,
            'scanned_raw' => $raw !== '' ? mb_substr($raw, 0, 1000) : null,
        ], $problem];
    }

    /**
     * @return array{0: ?array<string, mixed>, 1: ?string}
     */
    private function batchLine(Surgery $surgery, StockBatch $batch, int $quantity, string $raw, ?string $overrideReason): array
    {
        $warnings = [];

        if ($batch->branch_id !== $surgery->branch_id) {
            $warnings[] = self::WARN_NOT_IN_BRANCH;
        }

        $inKit = in_array($batch->location_type, [LocationType::Kit, LocationType::Hospital], true);

        if ($inKit && $batch->kit_id !== $this->checkoutKitId($surgery)) {
            $warnings[] = self::WARN_NOT_IN_KIT;
        }

        $problem = match (true) {
            $quantity > $batch->quantity => __('Only :available of lot :lot are available (asked: :asked).', ['available' => $batch->quantity, 'lot' => $batch->lot ?? '—', 'asked' => $quantity]),
            $batch->isBlockedByExpiry() && blank($overrideReason) => __(':code is expired — give a reason to use it anyway (needs permission).', ['code' => $batch->lot ?? '—']),
            default => null,
        };

        return [[
            'product_id' => $batch->product_id,
            'stock_batch_id' => $batch->id,
            'quantity' => $quantity,
            'source' => $inKit ? SurgeryItemSource::Kit : SurgeryItemSource::Shelf,
            'kit_id' => $inKit ? $batch->kit_id : null,
            'warnings' => $warnings === [] ? null : $warnings,
            'override_reason' => $batch->isBlockedByExpiry() ? $overrideReason : null,
            'scanned_raw' => $raw !== '' ? mb_substr($raw, 0, 1000) : null,
        ], $problem];
    }

    /**
     * Lots: the kit at the hospital first, then the branch shelf (FEFO, enough quantity).
     */
    private function pickBucket(Surgery $surgery, Product $product, int $quantity, ?string $lot, ?string $expiry): ?StockBatch
    {
        $base = fn () => StockBatch::query()
            ->where('product_id', $product->id)
            ->where('quantity', '>=', $quantity)
            ->when(filled($lot), fn ($q) => $q->where('lot', $lot))
            ->when(filled($expiry), fn ($q) => $q->where('expiry_date', $expiry))
            ->orderByRaw('expiry_date IS NULL')
            ->orderBy('expiry_date')
            ->orderBy('id');

        $kitId = $this->checkoutKitId($surgery);

        if ($kitId !== null && ($batch = $base()->where('kit_id', $kitId)->whereIn('location_type', [LocationType::Hospital, LocationType::Kit])->first()) !== null) {
            return $batch;
        }

        return $base()->where('branch_id', $surgery->branch_id)->where('location_type', LocationType::Shelf)
            ->get()->first(fn (StockBatch $b) => ! $b->isBlockedByExpiry())
            ?? $base()->where('branch_id', $surgery->branch_id)->where('location_type', LocationType::Shelf)->first();
    }

    private function checkoutKitId(Surgery $surgery): ?int
    {
        if ($surgery->kit_checkout_id === null) {
            return null;
        }

        return KitCheckout::query()->whereKey($surgery->kit_checkout_id)->where('status', KitCheckoutStatus::Out)->value('kit_id');
    }

    /**
     * @return list<string>
     */
    private function headerProblems(Surgery $surgery): array
    {
        return array_values(array_filter([
            $surgery->surgery_type_id === null ? __('Choose the surgery type.') : null,
            $surgery->customer_id === null ? __('Choose the hospital.') : null,
            $surgery->patient_id === null ? __('Choose or add the patient.') : null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function patientId(array $payload, Branch $branch, User $by, ?Surgery $existing = null): ?int
    {
        if (filled($payload['patient_id'] ?? null)) {
            $patient = Patient::query()->find($payload['patient_id']);

            if ($patient !== null && ! $this->patients->canSee($by, $patient)) {
                throw new AuthorizationException(__('This patient is not one of yours.'));
            }

            return $patient?->id;
        }

        $new = (array) ($payload['new_patient'] ?? []);

        if (blank($new['full_name'] ?? null)) {
            return null;
        }

        // A re-sent draft keeps the patient its first send created.
        if ($existing?->patient_id !== null && mb_strtolower(trim((string) $existing->patient?->full_name)) === mb_strtolower(trim((string) $new['full_name']))) {
            return $existing->patient_id;
        }

        // An offline "new" patient may already exist (same phone and name).
        return ($this->patients->findByPhone($new['phone'] ?? null, $new['full_name']) ?? $this->patients->create($branch, $new, $by))->id;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function syncDoctors(Surgery $surgery, array $payload, User $by): void
    {
        $lead = (int) ($payload['lead_doctor_id'] ?? 0) ?: $by->id;
        $doctors = [$lead => ['role' => SurgeryDoctorRole::Lead->value]];

        foreach ((array) ($payload['assistant_ids'] ?? []) as $id) {
            if ((int) $id > 0 && (int) $id !== $lead) {
                $doctors[(int) $id] = ['role' => SurgeryDoctorRole::Assistant->value];
            }
        }

        $surgery->doctors()->sync($doctors);
    }

    private function authorize(User $by, string $permission, int $branchId): void
    {
        if (! $this->scope->allows($by, $permission, $branchId)) {
            throw new AuthorizationException(__('You are not allowed to do this in this branch.'));
        }
    }

    /**
     * Warning codes → readable text.
     */
    public static function warningLabel(string $code): string
    {
        return match ($code) {
            self::WARN_NOT_IN_BRANCH => __('Not in this branch'),
            self::WARN_NOT_IN_KIT => __('Not in the kit of this surgery'),
            default => $code,
        };
    }
}
