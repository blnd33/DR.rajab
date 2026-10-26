<?php

namespace App\Services\Assets;

use App\Enums\AssetMovementType;
use App\Enums\CheckoutItemKind;
use App\Enums\CheckoutItemOutcome;
use App\Enums\InstrumentStatus;
use App\Enums\IssueSeverity;
use App\Enums\IssueType;
use App\Enums\KitCheckoutStatus;
use App\Enums\KitStatus;
use App\Enums\LocationType;
use App\Enums\MovementType;
use App\Enums\StockStatus;
use App\Enums\SurgeryStatus;
use App\Exceptions\Domain\StockRuleViolation;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Instrument;
use App\Models\Kit;
use App\Models\KitCheckout;
use App\Models\KitCheckoutItem;
use App\Models\Patient;
use App\Models\StockBatch;
use App\Models\StockUnit;
use App\Models\SurgeryItem;
use App\Models\User;
use App\Notifications\DocumentEvent;
use App\Services\Barcode\ScanResolver;
use App\Services\Barcode\ScanResultKind;
use App\Services\Issues\IssueService;
use App\Services\Movement\Notifier;
use App\Services\Numbering\DocumentNumberGenerator;
use App\Services\Permissions\BranchScopeResolver;
use App\Services\Settings\SettingsRepository;
use App\Services\Stock\StockMovementService;
use App\Support\Msg;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The full kit flow (Module 18, P3-6 / P3-7): create, prepare / refill against the template,
 * check out to a hospital, return with reconciliation, resolve unaccounted implants.
 */
class KitService
{
    public function __construct(
        private readonly StockMovementService $stock,
        private readonly AssetMovementService $assets,
        private readonly BranchScopeResolver $scope,
        private readonly DocumentNumberGenerator $numbers,
        private readonly IssueService $issues,
        private readonly Notifier $notifier,
        private readonly SettingsRepository $settings,
        private readonly AssetLookup $lookup,
        private readonly ScanResolver $scanner,
    ) {}

    /**
     * @param  array{kit_type_id: int, code?: ?string, barcode?: ?string, notes?: ?string, photo_path?: ?string}  $data
     */
    public function create(Branch $home, array $data, User $by): Kit
    {
        $this->authorize($by, 'kits.manage', $home->id);

        return DB::transaction(function () use ($home, $data, $by) {
            $kit = new Kit([
                'code' => filled($data['code'] ?? null) ? $data['code'] : $this->numbers->next('KIT', $home, 0, 4),
                'barcode' => $data['barcode'] ?? null,
                'kit_type_id' => $data['kit_type_id'],
                'home_branch_id' => $home->id,
                'branch_id' => $home->id,
                'notes' => $data['notes'] ?? null,
                'photo_path' => $data['photo_path'] ?? null,
            ]);
            $kit->status = KitStatus::Available;
            $kit->save();

            $this->assets->move($kit, AssetMovementType::Create, $by);

            return $kit;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Prepare / refill
    |--------------------------------------------------------------------------
    */

    /**
     * Scan anything into the kit: an instrument label, a serial or a lot box (1 per scan).
     *
     * @return array{kind: string, code: string}
     */
    public function addByScan(Kit $kit, string $raw, User $by): array
    {
        if (($instrument = $this->lookup->instrument($raw)) !== null) {
            $this->addInstrument($kit, $instrument, $by);

            return ['kind' => 'instrument', 'code' => $instrument->code];
        }

        $result = $this->scanner->resolve($raw, user: $by, branchId: $kit->branch_id);

        if ($result->kind === ScanResultKind::Unit && $result->unit !== null) {
            $this->addUnit($kit, $result->unit, $by);

            return ['kind' => 'unit', 'code' => $result->unit->serial];
        }

        if ($result->product !== null && ! $result->product->isSerialTracked()) {
            $batch = StockBatch::query()
                ->where('branch_id', $kit->branch_id)
                ->where('product_id', $result->product->id)
                ->where('location_type', LocationType::Shelf)
                ->where('quantity', '>', 0)
                ->when($result->parsed->lot !== null, fn ($q) => $q->where('lot', $result->parsed->lot))
                ->orderByRaw('expiry_date IS NULL')->orderBy('expiry_date')
                ->first() ?? throw new StockRuleViolation(__('No :sku on the shelf in :from.', ['sku' => $result->product->sku, 'from' => $kit->branch->code]));

            $this->addBatch($kit, $batch, 1, $by);

            return ['kind' => 'batch', 'code' => $batch->lot ?? $result->product->sku];
        }

        if ($result->product !== null) {
            throw new StockRuleViolation(__('Scan the serial number of the unit, not only the product.'));
        }

        throw new StockRuleViolation(__('Code not recognised.'));
    }

    public function addInstrument(Kit $kit, Instrument $instrument, User $by): void
    {
        $this->authorize($by, 'kits.prepare', $kit->branch_id);
        $this->assertAvailable($kit);

        if ($instrument->kit_id === $kit->id) {
            throw new StockRuleViolation(__(':code is already in this kit.', ['code' => $instrument->code]));
        }

        if ($instrument->branch_id !== $kit->branch_id || $instrument->status !== InstrumentStatus::Available) {
            throw new StockRuleViolation(__('Instrument :code is :status in :branch and cannot go into this kit.', [
                'code' => $instrument->code, 'status' => $instrument->status->getLabel(), 'branch' => $instrument->branch->code,
            ]));
        }

        $this->assets->move($instrument, AssetMovementType::AddToKit, $by, ['status' => InstrumentStatus::InKit, 'kit_id' => $kit->id], $kit);
    }

    public function removeInstrument(Instrument $instrument, User $by): void
    {
        $kit = $instrument->kit ?? throw new StockRuleViolation(__(':code is not in a kit.', ['code' => $instrument->code]));
        $this->authorize($by, 'kits.prepare', $kit->branch_id);
        $this->assertAvailable($kit);

        $this->assets->move($instrument, AssetMovementType::RemoveFromKit, $by, ['status' => InstrumentStatus::Available, 'kit_id' => null], $kit);
    }

    public function addUnit(Kit $kit, StockUnit $unit, User $by): void
    {
        $this->assertAvailable($kit);

        if ($unit->kit_id === $kit->id) {
            throw new StockRuleViolation(__(':code is already in this kit.', ['code' => $unit->serial]));
        }

        DB::transaction(fn () => $this->stock->loadUnitIntoKit($unit, $kit, $by, $kit, (string) Str::uuid()));
    }

    public function removeUnit(StockUnit $unit, User $by): void
    {
        $kit = $unit->kit ?? throw new StockRuleViolation(__('Unit :serial is not in a kit.', ['serial' => $unit->serial]));
        $this->assertAvailable($kit);

        DB::transaction(fn () => $this->stock->unloadUnitFromKit($unit, $by, $kit, (string) Str::uuid()));
    }

    public function addBatch(Kit $kit, StockBatch $batch, int $quantity, User $by): StockBatch
    {
        $this->assertAvailable($kit);

        return DB::transaction(fn () => $this->stock->loadBatchIntoKit($batch, $quantity, $kit, $by, $kit, (string) Str::uuid()));
    }

    public function removeBatch(StockBatch $kitBatch, int $quantity, User $by): void
    {
        $kit = $kitBatch->kit ?? throw new StockRuleViolation(__('This lot is not in a kit.'));
        $this->assertAvailable($kit);

        DB::transaction(fn () => $this->stock->unloadBatchFromKit($kitBatch, $quantity, $by, $kit, (string) Str::uuid()));
    }

    public function markPrepared(Kit $kit, User $by, ?string $note = null): void
    {
        $this->authorize($by, 'kits.prepare', $kit->branch_id);
        $this->assertAvailable($kit);

        DB::transaction(function () use ($kit, $by, $note) {
            $kit->forceFill(['last_prepared_at' => now()])->save();
            $this->assets->move($kit, AssetMovementType::Prepare, $by, note: $note);
        });
    }

    /**
     * The kit against its template: expected, present and missing per line, plus extras.
     *
     * @return array{lines: list<array{label: string, kind: string, expected: int, present: int, missing: int, required: bool}>, extras: list<array{label: string, quantity: int}>, complete: bool}
     */
    public function compare(Kit $kit): array
    {
        $kit->loadMissing(['type.templateItems.product', 'type.templateItems.instrumentType']);

        $instruments = Instrument::query()->where('kit_id', $kit->id)->whereIn('status', [InstrumentStatus::InKit, InstrumentStatus::OutAtHospital])->with('type')->get();
        $units = StockUnit::query()->where('kit_id', $kit->id)->whereIn('status', [StockStatus::InKit, StockStatus::OutAtHospital])->with('product')->get();
        $batches = StockBatch::query()->where('kit_id', $kit->id)->where('quantity', '>', 0)->with('product')->get();

        $byType = $instruments->countBy('instrument_type_id');
        $byProduct = $units->countBy('product_id');

        foreach ($batches as $batch) {
            $byProduct[$batch->product_id] = ($byProduct[$batch->product_id] ?? 0) + $batch->quantity;
        }

        $lines = [];
        $expectedTypes = [];
        $expectedProducts = [];

        foreach ($kit->type->templateItems as $item) {
            $present = $item->product_id !== null ? (int) ($byProduct[$item->product_id] ?? 0) : (int) ($byType[$item->instrument_type_id] ?? 0);
            $item->product_id !== null ? $expectedProducts[$item->product_id] = true : $expectedTypes[$item->instrument_type_id] = true;

            $lines[] = [
                'label' => $item->label(),
                'kind' => $item->item_kind->value,
                'expected' => $item->expected_qty,
                'present' => $present,
                'missing' => max(0, $item->expected_qty - $present),
                'required' => $item->is_required,
            ];
        }

        $extras = [];

        foreach ($instruments->groupBy('instrument_type_id') as $typeId => $group) {
            if (! isset($expectedTypes[$typeId])) {
                $extras[] = ['label' => $group->first()->type->name, 'quantity' => $group->count()];
            }
        }

        foreach ($byProduct as $productId => $quantity) {
            if (! isset($expectedProducts[$productId])) {
                $product = $units->firstWhere('product_id', $productId)->product ?? $batches->firstWhere('product_id', $productId)->product;
                $extras[] = ['label' => trim($product->sku.' · '.$product->name.' '.($product->size ?? '')), 'quantity' => (int) $quantity];
            }
        }

        $complete = collect($lines)->every(fn ($l) => ! $l['required'] || $l['missing'] === 0);

        return ['lines' => $lines, 'extras' => $extras, 'complete' => $complete];
    }

    /*
    |--------------------------------------------------------------------------
    | Check out → return → reconcile
    |--------------------------------------------------------------------------
    */

    public function checkout(Kit $kit, Customer $hospital, User $by, ?User $doctor = null, ?Patient $patient = null, ?string $plannedDate = null, ?string $notes = null): KitCheckout
    {
        $this->authorize($by, 'kits.checkout', $kit->branch_id);

        $checkout = DB::transaction(function () use ($kit, $hospital, $by, $doctor, $patient, $plannedDate, $notes) {
            /** @var Kit $kit */
            $kit = Kit::query()->with('branch')->lockForUpdate()->findOrFail($kit->id);
            $this->assertAvailable($kit);

            $days = max(1, $this->settings->int('kits.overdue_days') ?: (int) config('orthotrack.settings.kits.overdue_days', 7));
            $checkout = KitCheckout::query()->create([
                'number' => $this->numbers->next('KCO', $kit->branch),
                'kit_id' => $kit->id,
                'branch_id' => $kit->branch_id,
                'customer_id' => $hospital->id,
                'doctor_id' => $doctor?->id,
                'patient_id' => $patient?->id,
                'planned_surgery_date' => $plannedDate,
                'due_back_at' => now()->addDays($days),
                'status' => KitCheckoutStatus::Out,
                'checked_out_by' => $by->id,
                'checked_out_at' => now(),
                'notes' => $notes,
            ]);

            $group = (string) Str::uuid();

            foreach (Instrument::query()->where('kit_id', $kit->id)->where('status', InstrumentStatus::InKit)->get() as $instrument) {
                $this->assets->move($instrument, AssetMovementType::Checkout, $by, ['status' => InstrumentStatus::OutAtHospital, 'customer_id' => $hospital->id], $checkout, $group);
                $checkout->items()->create(['item_kind' => CheckoutItemKind::Instrument, 'instrument_id' => $instrument->id, 'qty_out' => 1]);
            }

            foreach (StockUnit::query()->where('kit_id', $kit->id)->where('status', StockStatus::InKit)->get() as $unit) {
                $this->stock->checkoutKitUnit($unit, $hospital, $by, $checkout, $group);
                $checkout->items()->create(['item_kind' => CheckoutItemKind::Unit, 'stock_unit_id' => $unit->id, 'product_id' => $unit->product_id, 'qty_out' => 1]);
            }

            foreach (StockBatch::query()->where('kit_id', $kit->id)->where('location_type', LocationType::Kit)->where('quantity', '>', 0)->get() as $batch) {
                $quantity = $batch->quantity;
                $atHospital = $this->stock->checkoutKitBatch($batch, $hospital, $by, $checkout, $group);
                $checkout->items()->create(['item_kind' => CheckoutItemKind::Batch, 'stock_batch_id' => $atHospital->id, 'product_id' => $batch->product_id, 'qty_out' => $quantity]);
            }

            $this->assets->move($kit, AssetMovementType::Checkout, $by, [
                'status' => KitStatus::OutAtHospital,
                'customer_id' => $hospital->id,
                'current_checkout_id' => $checkout->id,
            ], $checkout, $group);

            return $checkout;
        });

        return $checkout->refresh();
    }

    /**
     * The kit is back: instruments ticked, implants scanned, lots counted. Every line is
     * reconciled as returned / used in surgery / unaccounted; unaccounted opens one issue.
     *
     * @param  array<int, string>  $instrumentResults  instrument id → returned | missing | damaged
     * @param  list<int>  $scannedUnitIds
     * @param  array<int, int>  $lotReturned  checkout item id → quantity back
     */
    public function return(KitCheckout $checkout, array $instrumentResults, array $scannedUnitIds, array $lotReturned, User $by, ?string $notes = null): KitCheckout
    {
        $this->authorize($by, 'kits.return', $checkout->branch_id);

        $checkout = DB::transaction(function () use ($checkout, $instrumentResults, $scannedUnitIds, $lotReturned, $by, $notes) {
            /** @var KitCheckout $checkout */
            $checkout = KitCheckout::query()->with(['items.instrument', 'items.stockUnit', 'items.stockBatch', 'kit'])->lockForUpdate()->findOrFail($checkout->id);

            if ($checkout->status !== KitCheckoutStatus::Out) {
                throw new StockRuleViolation(__('Kit checkout :number was already returned.', ['number' => $checkout->number]));
            }

            $kit = $checkout->kit;
            $group = (string) Str::uuid();
            $unaccounted = 0;
            $problems = 0;

            foreach ($checkout->items as $item) {
                match ($item->item_kind) {
                    CheckoutItemKind::Instrument => $problems += $this->returnInstrument($item, $kit, $instrumentResults[$item->instrument_id] ?? 'returned', $by, $checkout, $group),
                    CheckoutItemKind::Unit => $unaccounted += $this->returnUnit($item, $kit, in_array($item->stock_unit_id, $scannedUnitIds, true), $by, $checkout, $group),
                    CheckoutItemKind::Batch => $unaccounted += $this->returnBatch($item, $kit, (int) ($lotReturned[$item->id] ?? 0), $by, $checkout, $group),
                };
            }

            $checkout->forceFill([
                'status' => $unaccounted + $problems > 0 ? KitCheckoutStatus::ReconciledWithIssues : KitCheckoutStatus::Reconciled,
                'returned_by' => $by->id,
                'returned_at' => now(),
                'reconciled_by' => $by->id,
                'reconciled_at' => now(),
                'notes' => trim(($checkout->notes ?? '')."\n".($notes ?? '')) ?: null,
            ])->save();

            $this->assets->move($kit, AssetMovementType::Return, $by, [
                'status' => KitStatus::Available,
                'customer_id' => null,
                'current_checkout_id' => null,
            ], $checkout, $group);

            if ($unaccounted > 0) {
                $issue = $this->issues->open(
                    IssueType::UnaccountedImplant,
                    IssueSeverity::Critical,
                    $checkout->branch_id,
                    $checkout,
                    trans_choice('Kit checkout :number: :count item unaccounted|Kit checkout :number: :count items unaccounted', $unaccounted, ['number' => $checkout->number]),
                    ['checkout' => $checkout->number, 'kit' => $kit->code, 'unaccounted' => $unaccounted],
                );
                $checkout->items()->where('outcome', CheckoutItemOutcome::Unaccounted)->update(['issue_id' => $issue->id]);
            }

            return $checkout;
        });

        if ($checkout->status === KitCheckoutStatus::ReconciledWithIssues && $checkout->items()->where('outcome', CheckoutItemOutcome::Unaccounted)->exists()) {
            $this->notifier->toBranch('kits.resolve_unaccounted', $checkout->branch_id, new DocumentEvent(
                'kits.unaccounted',
                Msg::key('Unaccounted implants after kit :kit came back'),
                ['kit' => $checkout->kit->code],
                Msg::key('Kit checkout :number at :hospital.'),
                ['number' => $checkout->number, 'hospital' => $checkout->customer->code],
                route('filament.admin.resources.kit-checkouts.view', $checkout),
                'heroicon-o-exclamation-triangle',
            ), $by);
        }

        return $checkout;
    }

    /**
     * Settle an unaccounted line: found (back in the kit), used (a surgery now has it),
     * or lost (written off — the Super Admin's decision).
     */
    public function resolveUnaccounted(KitCheckoutItem $item, string $resolution, User $by, ?string $note = null): KitCheckoutItem
    {
        $checkout = $item->checkout;
        $this->authorize($by, 'kits.resolve_unaccounted', $checkout->branch_id);

        DB::transaction(function () use ($item, $resolution, $by, $note, $checkout) {
            /** @var KitCheckoutItem $item */
            $item = KitCheckoutItem::query()->with(['stockUnit', 'stockBatch'])->lockForUpdate()->findOrFail($item->id);

            if ($item->outcome !== CheckoutItemOutcome::Unaccounted || $item->qty_unaccounted < 1) {
                throw new StockRuleViolation(__('This line is already settled.'));
            }

            $kit = $checkout->kit;
            $group = (string) Str::uuid();
            $quantity = $item->qty_unaccounted;

            switch ($resolution) {
                case 'found':
                    if ($kit->status !== KitStatus::Available) {
                        throw new StockRuleViolation(__('Kit :kit is out again. Put the item on the shelf after the kit is back.', ['kit' => $kit->code]));
                    }

                    $item->item_kind === CheckoutItemKind::Unit
                        ? $this->stock->returnKitUnit($item->stockUnit, $kit, $by, $checkout, $group, 'kits.resolve_unaccounted')
                        : $this->stock->returnKitBatch($item->stockBatch, $quantity, $kit, $by, $checkout, $group, 'kits.resolve_unaccounted');
                    $item->qty_returned += $quantity;
                    $item->outcome = CheckoutItemOutcome::Returned;
                    break;

                case 'used':
                    $used = $this->usedQuantity($item);

                    if ($used < $item->qty_used + $quantity) {
                        throw new StockRuleViolation(__('No surgery uses this item yet. Log the surgery first.'));
                    }

                    $item->qty_used += $quantity;
                    $item->surgery_item_id ??= $this->surgeryItemFor($item)?->id;
                    $item->outcome = CheckoutItemOutcome::UsedInSurgery;
                    break;

                case 'lost':
                    $reason = $note ?? __('Not returned with kit :kit (:number)', ['kit' => $kit->code, 'number' => $checkout->number]);
                    $item->item_kind === CheckoutItemKind::Unit
                        ? $this->stock->moveUnitOut($item->stockUnit, StockStatus::Lost, MovementType::WriteOff, $by, $checkout, $group, 'kits.resolve_unaccounted', $reason)
                        : $this->stock->removeFromBatch($item->stockBatch, $quantity, MovementType::WriteOff, $by, $checkout, $group, 'kits.resolve_unaccounted', $reason);
                    $item->outcome = CheckoutItemOutcome::Missing;
                    break;

                default:
                    throw new StockRuleViolation(__('Choose what happened.'));
            }

            $item->qty_unaccounted = 0;
            $item->forceFill(['resolved_by' => $by->id, 'resolved_at' => now(), 'note' => $note ?? $item->note])->save();
        });

        if (! $checkout->items()->where('qty_unaccounted', '>', 0)->exists()) {
            $this->issues->resolveFor($checkout, IssueType::UnaccountedImplant, __('All unaccounted items settled.'), $by);
        }

        return $item->refresh();
    }

    /** @return int 1 when the instrument came back missing or damaged */
    private function returnInstrument(KitCheckoutItem $item, Kit $kit, string $result, User $by, KitCheckout $checkout, string $group): int
    {
        $instrument = $item->instrument;

        [$status, $type, $outcome, $kitId] = match ($result) {
            'missing' => [InstrumentStatus::Missing, AssetMovementType::MarkMissing, CheckoutItemOutcome::Missing, null],
            'damaged' => [InstrumentStatus::Damaged, AssetMovementType::MarkDamaged, CheckoutItemOutcome::Damaged, null],
            default => [InstrumentStatus::InKit, AssetMovementType::Return, CheckoutItemOutcome::Returned, $kit->id],
        };

        $this->assets->move($instrument, $type, $by, ['status' => $status, 'kit_id' => $kitId, 'customer_id' => null, 'branch_id' => $kit->branch_id], $checkout, $group);
        $item->forceFill(['outcome' => $outcome, 'qty_returned' => $outcome === CheckoutItemOutcome::Returned ? 1 : 0])->save();

        return $outcome === CheckoutItemOutcome::Returned ? 0 : 1;
    }

    /** @return int quantity unaccounted */
    private function returnUnit(KitCheckoutItem $item, Kit $kit, bool $scanned, User $by, KitCheckout $checkout, string $group): int
    {
        $unit = $item->stockUnit->fresh();

        if ($unit->status === StockStatus::UsedInSurgery) {
            $item->forceFill(['outcome' => CheckoutItemOutcome::UsedInSurgery, 'qty_used' => 1, 'surgery_item_id' => $this->surgeryItemFor($item)?->id])->save();

            return 0;
        }

        if ($scanned && $unit->status === StockStatus::OutAtHospital) {
            $this->stock->returnKitUnit($unit, $kit, $by, $checkout, $group);
            $item->forceFill(['outcome' => CheckoutItemOutcome::Returned, 'qty_returned' => 1])->save();

            return 0;
        }

        $item->forceFill(['outcome' => CheckoutItemOutcome::Unaccounted, 'qty_unaccounted' => 1])->save();

        return 1;
    }

    /** @return int quantity unaccounted */
    private function returnBatch(KitCheckoutItem $item, Kit $kit, int $returned, User $by, KitCheckout $checkout, string $group): int
    {
        $bucket = $item->stockBatch->fresh();
        $used = min($item->qty_out, $this->usedQuantity($item));
        $returned = max(0, min($returned, $item->qty_out - $used, $bucket->quantity));

        if ($returned > 0) {
            $this->stock->returnKitBatch($bucket, $returned, $kit, $by, $checkout, $group);
        }

        $unaccounted = $item->qty_out - $used - $returned;

        $item->forceFill([
            'qty_used' => $used,
            'qty_returned' => $returned,
            'qty_unaccounted' => $unaccounted,
            'surgery_item_id' => $used > 0 ? $this->surgeryItemFor($item)?->id : null,
            'outcome' => match (true) {
                $unaccounted > 0 => CheckoutItemOutcome::Unaccounted,
                $returned === 0 && $used > 0 => CheckoutItemOutcome::UsedInSurgery,
                default => CheckoutItemOutcome::Returned,
            },
        ])->save();

        return $unaccounted;
    }

    /**
     * How much of a checkout line completed surgeries took.
     */
    private function usedQuantity(KitCheckoutItem $item): int
    {
        return (int) SurgeryItem::query()
            ->whereNull('reversed_at')
            ->whereHas('surgery', fn ($q) => $q->where('status', SurgeryStatus::Completed))
            ->when($item->stock_unit_id !== null, fn ($q) => $q->where('stock_unit_id', $item->stock_unit_id))
            ->when($item->stock_unit_id === null, fn ($q) => $q->where('stock_batch_id', $item->stock_batch_id))
            ->sum('quantity');
    }

    private function surgeryItemFor(KitCheckoutItem $item): ?SurgeryItem
    {
        return SurgeryItem::query()
            ->whereNull('reversed_at')
            ->when($item->stock_unit_id !== null, fn ($q) => $q->where('stock_unit_id', $item->stock_unit_id))
            ->when($item->stock_unit_id === null, fn ($q) => $q->where('stock_batch_id', $item->stock_batch_id))
            ->latest('id')
            ->first();
    }

    private function assertAvailable(Kit $kit): void
    {
        if ($kit->status !== KitStatus::Available) {
            throw new StockRuleViolation(__('Kit :kit is :status.', ['kit' => $kit->code, 'status' => $kit->status->getLabel()]));
        }
    }

    private function authorize(User $by, string $permission, int $branchId): void
    {
        if (! $this->scope->allows($by, $permission, $branchId)) {
            throw new AuthorizationException(__('You are not allowed to do this in this branch.'));
        }
    }
}
