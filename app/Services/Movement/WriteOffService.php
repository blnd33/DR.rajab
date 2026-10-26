<?php

namespace App\Services\Movement;

use App\Enums\AttachmentKind;
use App\Enums\LocationType;
use App\Enums\MovementType;
use App\Enums\StockStatus;
use App\Enums\WriteOffReason;
use App\Enums\WriteOffStatus;
use App\Exceptions\Domain\StockRuleViolation;
use App\Models\Attachment;
use App\Models\Branch;
use App\Models\StockBatch;
use App\Models\StockUnit;
use App\Models\User;
use App\Models\WriteOff;
use App\Models\WriteOffItem;
use App\Notifications\DocumentEvent;
use App\Services\Money\FxService;
use App\Services\Numbering\DocumentNumberGenerator;
use App\Services\Permissions\BranchScopeResolver;
use App\Services\Stock\StockMovementService;
use App\Support\Msg;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Write-offs (Module 15): report → pending → approve (stock moves now) or reject.
 */
class WriteOffService
{
    public function __construct(
        private readonly StockMovementService $stock,
        private readonly BranchScopeResolver $scope,
        private readonly DocumentNumberGenerator $numbers,
        private readonly Notifier $notifier,
        private readonly FxService $fx,
    ) {}

    /**
     * @param  list<array{stock_unit_id?: ?int, stock_batch_id?: ?int, quantity?: ?int}>  $items
     * @param  list<UploadedFile>  $photos
     */
    public function report(Branch $branch, WriteOffReason $reason, array $items, User $by, ?string $note = null, array $photos = [], ?Model $source = null, string $permission = 'write_offs.report'): WriteOff
    {
        $this->authorize($by, $permission, $branch);

        if ($items === []) {
            throw new StockRuleViolation(__('Add at least one item.'));
        }

        $target = match ($reason) {
            WriteOffReason::Lost => StockStatus::Lost,
            WriteOffReason::Expired => StockStatus::ExpiredWrittenOff,
            default => StockStatus::Damaged,
        };

        $writeOff = DB::transaction(function () use ($branch, $reason, $items, $by, $note, $source, $target) {
            $writeOff = new WriteOff([
                'number' => $this->numbers->next('WOF', $branch),
                'branch_id' => $branch->id,
                'reason' => $reason,
                'status' => WriteOffStatus::Pending,
                'note' => $note,
                'reported_by' => $by->id,
                'reported_at' => now(),
            ]);

            if ($source !== null) {
                $writeOff->source()->associate($source);
            }

            $writeOff->save();
            $units = [];

            foreach ($items as $line) {
                if (filled($line['stock_unit_id'] ?? null)) {
                    $unit = StockUnit::query()->findOrFail($line['stock_unit_id']);

                    if ($unit->branch_id !== $branch->id || ! $unit->status->isOnHand()) {
                        throw new StockRuleViolation(__('Unit :serial is not in stock in :branch.', ['serial' => $unit->serial, 'branch' => $branch->code]), 'serial');
                    }

                    if (isset($units[$unit->id]) || $this->unitPending($unit, $writeOff->id)) {
                        throw new StockRuleViolation(__('Unit :serial is waiting in a write-off.', ['serial' => $unit->serial]), 'serial');
                    }

                    $units[$unit->id] = true;
                    $writeOff->items()->create([
                        'product_id' => $unit->product_id,
                        'stock_unit_id' => $unit->id,
                        'quantity' => 1,
                        'target_status' => $target,
                        'unit_cost' => $unit->unit_cost,
                        'cost_currency' => $unit->cost_currency,
                    ]);

                    continue;
                }

                $batch = StockBatch::query()->findOrFail($line['stock_batch_id'] ?? 0);
                $quantity = (int) ($line['quantity'] ?? 0);

                if ($batch->branch_id !== $branch->id || $batch->location_type !== LocationType::Shelf || $quantity < 1 || $quantity > $batch->quantity) {
                    throw new StockRuleViolation(__('Only :available of lot :lot are on the shelf (asked: :asked).', [
                        'available' => $batch->quantity, 'lot' => $batch->lot ?? '—', 'asked' => $quantity,
                    ]), 'quantity');
                }

                $writeOff->items()->create([
                    'product_id' => $batch->product_id,
                    'stock_batch_id' => $batch->id,
                    'quantity' => $quantity,
                    'target_status' => $target,
                    'unit_cost' => $batch->unit_cost,
                    'cost_currency' => $batch->cost_currency,
                ]);
            }

            return $writeOff;
        });

        foreach ($photos as $photo) {
            $this->attachPhoto($writeOff, $photo, $by);
        }

        $this->notifier->toBranch('write_offs.approve', $branch->id, new DocumentEvent(
            'write_offs.pending',
            Msg::key('Write-off :number waits for approval (:branch)'),
            ['number' => $writeOff->number, 'branch' => $branch->code],
            Msg::key(':reason · :count items'),
            ['reason' => $reason->getLabel(), 'count' => (int) $writeOff->items()->sum('quantity')],
            route('filament.admin.resources.write-offs.view', $writeOff),
            'heroicon-o-trash',
        ), $by);

        return $writeOff;
    }

    public function approve(WriteOff $writeOff, User $by): WriteOff
    {
        $this->authorize($by, 'write_offs.approve', $writeOff->branch_id);

        $writeOff = DB::transaction(function () use ($writeOff, $by) {
            /** @var WriteOff $writeOff */
            $writeOff = WriteOff::query()->with(['items.stockUnit', 'items.stockBatch'])->lockForUpdate()->findOrFail($writeOff->id);
            $this->assertPending($writeOff);

            $group = (string) Str::uuid();
            $reason = __('Write-off :number: :reason', ['number' => $writeOff->number, 'reason' => $writeOff->reason->getLabel()]);

            foreach ($writeOff->items as $item) {
                $item->stock_unit_id !== null
                    ? $this->stock->moveUnitOut($item->stockUnit, $item->target_status, MovementType::WriteOff, $by, $writeOff, $group, 'write_offs.approve', $reason)
                    : $this->stock->removeFromBatch($item->stockBatch, $item->quantity, MovementType::WriteOff, $by, $writeOff, $group, 'write_offs.approve', $reason);
            }

            $snapshot = $this->fx->current();
            $costUsd = $snapshot === null ? null : (string) collect($writeOff->costByCurrency())
                ->reduce(fn (BigDecimal $sum, $amount, $currency) => $sum->plus($this->fx->converter($snapshot)->toUsd((string) $amount, (string) $currency)), BigDecimal::zero());

            $writeOff->forceFill([
                'status' => WriteOffStatus::Approved,
                'approved_by' => $by->id,
                'approved_at' => now(),
                'total_cost_usd' => $costUsd,
                'fx_snapshot_id' => $snapshot?->id,
            ])->save();

            return $writeOff;
        });

        $this->decided($writeOff, Msg::key('Write-off :number was approved'), 'heroicon-o-check-circle');

        return $writeOff;
    }

    public function reject(WriteOff $writeOff, string $reason, User $by): WriteOff
    {
        $this->authorize($by, 'write_offs.approve', $writeOff->branch_id);

        if (blank($reason)) {
            throw new StockRuleViolation(__('A reason is required.'), 'reason');
        }

        $this->assertPending($writeOff);
        $writeOff->forceFill(['status' => WriteOffStatus::Rejected, 'rejected_by' => $by->id, 'rejected_at' => now(), 'rejection_reason' => $reason])->save();
        $this->decided($writeOff, Msg::key('Write-off :number was rejected'), 'heroicon-o-x-circle');

        return $writeOff;
    }

    public function cancel(WriteOff $writeOff, User $by): WriteOff
    {
        if ($writeOff->reported_by !== $by->id && ! $this->scope->allows($by, 'write_offs.approve', $writeOff->branch_id)) {
            throw new AuthorizationException(__('Only the person who reported it can cancel this write-off.'));
        }

        $this->assertPending($writeOff);
        $writeOff->forceFill(['status' => WriteOffStatus::Cancelled])->save();

        return $writeOff;
    }

    public function attachPhoto(WriteOff $writeOff, UploadedFile $photo, User $by): Attachment
    {
        $path = $photo->store('write-offs/'.$writeOff->id, 'local');

        return Attachment::query()->create([
            'attachable_type' => $writeOff->getMorphClass(),
            'attachable_id' => $writeOff->id,
            'kind' => AttachmentKind::Photo,
            'disk' => 'local',
            'path' => (string) $path,
            'original_name' => mb_substr($photo->getClientOriginalName(), 0, 191),
            'mime' => $photo->getMimeType(),
            'size_bytes' => (int) $photo->getSize(),
            'uploaded_by' => $by->id,
        ]);
    }

    private function unitPending(StockUnit $unit, int $exceptWriteOffId): bool
    {
        return WriteOffItem::query()->where('stock_unit_id', $unit->id)
            ->where('write_off_id', '<>', $exceptWriteOffId)
            ->whereHas('writeOff', fn ($q) => $q->where('status', WriteOffStatus::Pending))
            ->exists();
    }

    private function decided(WriteOff $writeOff, string $titleKey, string $icon): void
    {
        $this->notifier->toUser($writeOff->reporter, new DocumentEvent(
            'write_offs.decided',
            $titleKey,
            ['number' => $writeOff->number],
            $writeOff->rejection_reason !== null ? Msg::key('Reason: :reason') : null,
            $writeOff->rejection_reason !== null ? ['reason' => $writeOff->rejection_reason] : [],
            route('ops.tasks'),
            $icon,
        ));
    }

    private function assertPending(WriteOff $writeOff): void
    {
        if ($writeOff->status !== WriteOffStatus::Pending) {
            throw new StockRuleViolation(__('This write-off was already decided.'));
        }
    }

    private function authorize(User $by, string $permission, Branch|int $branch): void
    {
        $branchId = $branch instanceof Branch ? $branch->id : $branch;

        if (! $this->scope->allows($by, $permission, $branchId)) {
            throw new AuthorizationException(__('You are not allowed to do this in branch :code.', ['code' => (string) Branch::query()->whereKey($branchId)->value('code')]));
        }
    }
}
