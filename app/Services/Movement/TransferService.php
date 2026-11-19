<?php

namespace App\Services\Movement;

use App\Enums\AttachmentKind;
use App\Enums\InstrumentStatus;
use App\Enums\IssueSeverity;
use App\Enums\IssueType;
use App\Enums\KitStatus;
use App\Enums\LocationType;
use App\Enums\MovementType;
use App\Enums\ScanContext;
use App\Enums\StockRequestStatus;
use App\Enums\StockStatus;
use App\Enums\TransferLineResolution;
use App\Enums\TransferLineStatus;
use App\Enums\TransferStatus;
use App\Enums\WriteOffStatus;
use App\Exceptions\Domain\StockRuleViolation;
use App\Models\Attachment;
use App\Models\Branch;
use App\Models\Instrument;
use App\Models\Kit;
use App\Models\StockBatch;
use App\Models\StockRequest;
use App\Models\StockUnit;
use App\Models\Transfer;
use App\Models\TransferItem;
use App\Models\User;
use App\Models\WriteOffItem;
use App\Notifications\DocumentEvent;
use App\Services\Assets\AssetTransferService;
use App\Services\Barcode\ScanResolver;
use App\Services\Barcode\ScanResult;
use App\Services\Barcode\ScanResultKind;
use App\Services\Issues\IssueService;
use App\Services\Numbering\DocumentNumberGenerator;
use App\Services\Permissions\BranchScopeResolver;
use App\Services\Stock\StockMovementService;
use App\Support\Msg;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Inter-branch transfers (Module 8): create (scan units / pick lots) → send (in transit)
 * → receive by scanning (differences become an issue) → resolve differences.
 * Cancel only before receiving. All stock changes go through StockMovementService.
 */
class TransferService
{
    public const PHOTO_MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private readonly StockMovementService $stock,
        private readonly BranchScopeResolver $scope,
        private readonly DocumentNumberGenerator $numbers,
        private readonly IssueService $issues,
        private readonly Notifier $notifier,
        private readonly AssetTransferService $assetTransfers,
    ) {}

    public function createDraft(Branch $from, Branch $to, User $by, ?string $notes = null, ?StockRequest $request = null): Transfer
    {
        $this->authorize($by, 'transfers.create', $from);

        if ($from->id === $to->id) {
            throw new StockRuleViolation(__('Choose another station to send to.'), 'to_branch_id');
        }

        if (! $from->isActive() || ! $to->isActive()) {
            throw new StockRuleViolation(__('Both stations must be open.'), 'to_branch_id');
        }

        return DB::transaction(fn () => Transfer::query()->create([
            'number' => $this->numbers->next('TRF', $from),
            'from_branch_id' => $from->id,
            'to_branch_id' => $to->id,
            'status' => TransferStatus::Draft,
            'stock_request_id' => $request?->id,
            'notes' => $notes,
            'created_by' => $by->id,
        ]));
    }

    public function addUnit(Transfer $transfer, StockUnit $unit, User $by): TransferItem
    {
        $this->assertDraft($transfer);
        $this->authorize($by, 'transfers.create', $transfer->from_branch_id);

        if ($unit->branch_id !== $transfer->from_branch_id) {
            throw new StockRuleViolation(__('Unit :serial is in station :branch, not in :from.', [
                'serial' => $unit->serial, 'branch' => $unit->branch->code, 'from' => $transfer->fromBranch->code,
            ]), 'serial');
        }

        if ($unit->status !== StockStatus::InStock) {
            throw new StockRuleViolation(__('Unit :serial is :status and cannot be sent.', ['serial' => $unit->serial, 'status' => $unit->status->getLabel()]), 'serial');
        }

        if ($transfer->items()->where('stock_unit_id', $unit->id)->exists()) {
            throw new StockRuleViolation(__('Unit :serial is already on this transfer.', ['serial' => $unit->serial]), 'serial');
        }

        $this->assertNotReserved($unit, $transfer->id);

        return $transfer->items()->create([
            'product_id' => $unit->product_id,
            'stock_unit_id' => $unit->id,
            'quantity_sent' => 1,
        ]);
    }

    public function addBatch(Transfer $transfer, StockBatch $batch, int $quantity, User $by): TransferItem
    {
        $this->assertDraft($transfer);
        $this->authorize($by, 'transfers.create', $transfer->from_branch_id);

        if ($batch->branch_id !== $transfer->from_branch_id || $batch->location_type !== LocationType::Shelf) {
            throw new StockRuleViolation(__('This lot is not on a shelf in :from.', ['from' => $transfer->fromBranch->code]), 'lot');
        }

        $item = $transfer->items()->where('from_batch_id', $batch->id)->first();
        $total = $quantity + (int) $item?->quantity_sent;

        if ($quantity < 1 || $total > $batch->quantity) {
            throw new StockRuleViolation(__('Only :available of lot :lot are on the shelf (asked: :asked).', [
                'available' => $batch->quantity, 'lot' => $batch->lot ?? '—', 'asked' => $total,
            ]), 'quantity');
        }

        if ($item !== null) {
            $item->update(['quantity_sent' => $total]);

            return $item;
        }

        return $transfer->items()->create([
            'product_id' => $batch->product_id,
            'from_batch_id' => $batch->id,
            'quantity_sent' => $quantity,
        ]);
    }

    /**
     * Scan on the sending side: a unit is added, a lot scan adds 1 from the matching bucket
     * (FEFO when the label has no lot). Returns the resolved scan for the screen.
     */
    public function addByScan(Transfer $transfer, string $raw, User $by): ScanResult
    {
        $result = app(ScanResolver::class)->resolve($raw, ScanContext::Transfer, $by, $transfer->from_branch_id);

        if ($result->kind === ScanResultKind::Unit && $result->unit !== null) {
            $this->addUnit($transfer, $result->unit, $by);

            return $result;
        }

        if (in_array($result->kind, [ScanResultKind::Lot, ScanResultKind::Product], true) && $result->product !== null && ! $result->product->isSerialTracked()) {
            $batch = $result->batches
                ->where('branch_id', $transfer->from_branch_id)
                ->filter(fn (StockBatch $b) => $b->location_type === LocationType::Shelf && $b->quantity > 0)
                ->sortBy(fn (StockBatch $b) => $b->expiry_date->timestamp ?? PHP_INT_MAX)
                ->first();

            if ($batch === null) {
                throw new StockRuleViolation(__('No :sku on the shelf in :from.', ['sku' => $result->product->sku, 'from' => $transfer->fromBranch->code]), 'code');
            }

            $this->addBatch($transfer, $batch, 1, $by);

            return $result;
        }

        if ($result->kind === ScanResultKind::Product || $result->kind === ScanResultKind::NewUnit) {
            throw new StockRuleViolation(__('Scan the serial number of the unit, not only the product.'), 'code');
        }

        throw new StockRuleViolation(__('Code not recognised.'), 'code');
    }

    /**
     * Change the quantity of a lot line on a draft (0 removes it).
     */
    public function setBatchQuantity(TransferItem $item, int $quantity, User $by): ?TransferItem
    {
        $this->assertDraft($item->transfer);
        $this->authorize($by, 'transfers.create', $item->transfer->from_branch_id);

        if ($item->from_batch_id === null) {
            throw new StockRuleViolation(__('A serial unit always has quantity 1.'));
        }

        if ($quantity <= 0) {
            $item->delete();

            return null;
        }

        if ($quantity > $item->fromBatch->quantity) {
            throw new StockRuleViolation(__('Only :available of lot :lot are on the shelf (asked: :asked).', [
                'available' => $item->fromBatch->quantity, 'lot' => $item->fromBatch->lot ?? '—', 'asked' => $quantity,
            ]), 'quantity');
        }

        $item->update(['quantity_sent' => $quantity]);

        return $item;
    }

    public function removeItem(TransferItem $item, User $by): void
    {
        $this->assertDraft($item->transfer);
        $this->authorize($by, 'transfers.create', $item->transfer->from_branch_id);
        $item->delete();
    }

    /**
     * Draft → in transit: units become "in transit", lot quantities leave the shelf.
     */
    public function send(Transfer $transfer, User $by, ?string $overrideReason = null, ?UploadedFile $photo = null): Transfer
    {
        $this->authorize($by, 'transfers.send', $transfer->from_branch_id);

        if ($photo !== null) {
            $this->attachPhoto($transfer, $photo, AttachmentKind::TransferSent, $by);
        }

        if ($transfer->photo(AttachmentKind::TransferSent) === null) {
            throw new StockRuleViolation(__('Take a photo of the items before sending.'), 'photo');
        }

        $transfer = DB::transaction(function () use ($transfer, $by, $overrideReason) {
            /** @var Transfer $transfer */
            $transfer = Transfer::query()->with(['items.stockUnit', 'items.fromBatch', 'toBranch'])->lockForUpdate()->findOrFail($transfer->id);
            $this->assertDraft($transfer);

            if ($transfer->items->isEmpty() && $transfer->assets()->doesntExist()) {
                throw new StockRuleViolation(__('Add at least one item.'));
            }

            $group = (string) Str::uuid();

            foreach ($transfer->items as $item) {
                if ($item->stock_unit_id !== null) {
                    $this->assertNotReserved($item->stockUnit, $transfer->id);
                    $this->stock->sendUnit($item->stockUnit, $transfer->toBranch, $by, $transfer, $group, $overrideReason);
                } else {
                    $this->stock->sendBatch($item->fromBatch, $item->quantity_sent, $transfer->toBranch, $by, $transfer, $group, $overrideReason);
                }
            }

            $this->assetTransfers->sendAll($transfer, $by, $group);

            $transfer->forceFill(['status' => TransferStatus::InTransit, 'sent_by' => $by->id, 'sent_at' => now()])->save();

            return $transfer;
        });

        $this->notifier->toBranch('transfers.receive', $transfer->to_branch_id, new DocumentEvent(
            'transfers.sent',
            Msg::key('Transfer :number is on its way from :from'),
            ['number' => $transfer->number, 'from' => $transfer->fromBranch->code],
            Msg::key(':count items. Scan them when they arrive.'),
            ['count' => $transfer->totalQuantity()],
            route('ops.transfers.show', $transfer),
            'heroicon-o-truck',
        ), $by);

        return $transfer;
    }

    /**
     * Receive by scanning. Units not scanned are "missing", lots received short are "short",
     * scanned units not on the slip are "extra". Any difference opens an issue.
     *
     * @param  list<int>  $scannedUnitIds
     * @param  array<int, int>  $lotQuantities  transfer item id → quantity received
     * @param  list<string>  $extraCodes  codes scanned that are not on the slip
     */
    public function receive(Transfer $transfer, array $scannedUnitIds, array $lotQuantities, User $by, array $extraCodes = [], ?UploadedFile $photo = null): Transfer
    {
        $this->authorize($by, 'transfers.receive', $transfer->to_branch_id);

        if ($photo !== null) {
            $this->attachPhoto($transfer, $photo, AttachmentKind::TransferReceived, $by);
        }

        if ($transfer->photo(AttachmentKind::TransferReceived) === null) {
            throw new StockRuleViolation(__('Take a photo of the items as they arrived before confirming.'), 'photo');
        }

        $transfer = DB::transaction(function () use ($transfer, $scannedUnitIds, $lotQuantities, $by, $extraCodes) {
            /** @var Transfer $transfer */
            $transfer = Transfer::query()->with(['items.stockUnit', 'items.fromBatch', 'toBranch'])->lockForUpdate()->findOrFail($transfer->id);

            if ($transfer->status !== TransferStatus::InTransit) {
                throw new StockRuleViolation(__('Transfer :number is not in transit.', ['number' => $transfer->number]));
            }

            $group = (string) Str::uuid();
            $differences = 0;

            foreach ($transfer->items as $item) {
                if ($item->stock_unit_id !== null) {
                    if (in_array($item->stock_unit_id, $scannedUnitIds, true)) {
                        $this->stock->receiveUnit($item->stockUnit, $transfer->toBranch, $by, $transfer, $group);
                        $item->update(['quantity_received' => 1, 'line_status' => TransferLineStatus::Received]);
                    } else {
                        $item->update(['quantity_received' => 0, 'line_status' => TransferLineStatus::Missing]);
                        $differences++;
                    }

                    continue;
                }

                $received = max(0, min($item->quantity_sent, (int) ($lotQuantities[$item->id] ?? 0)));

                if ($received > 0) {
                    $target = $this->stock->receiveBatch($item->fromBatch, $received, $transfer->toBranch, $by, $transfer, $group);
                    $item->to_batch_id = $target->id;
                }

                $item->quantity_received = $received;
                $item->line_status = $received === $item->quantity_sent
                    ? TransferLineStatus::Received
                    : ($received === 0 ? TransferLineStatus::Missing : TransferLineStatus::Short);
                $item->save();

                if ($received !== $item->quantity_sent) {
                    $differences++;
                }
            }

            foreach (array_unique(array_filter(array_map('trim', $extraCodes))) as $code) {
                $unit = StockUnit::query()->where('serial', $code)->first();

                if ($unit !== null && $transfer->items->contains('stock_unit_id', $unit->id)) {
                    continue;
                }

                if ($unit === null && $transfer->items->isEmpty()) {
                    continue; // only kits on this transfer: nothing to attach an unknown code to
                }

                $transfer->items()->create([
                    'product_id' => $unit !== null ? $unit->product_id : $transfer->items->first()->product_id,
                    'stock_unit_id' => $unit?->id,
                    'quantity_sent' => 0,
                    'quantity_received' => 1,
                    'line_status' => TransferLineStatus::Extra,
                    'override_reason' => $unit === null ? __('Scanned code: :code', ['code' => $code]) : null,
                ]);
                $differences++;
            }

            $this->assetTransfers->receiveAll($transfer, $by, $group);

            $transfer->forceFill([
                'status' => $differences > 0 ? TransferStatus::ReceivedWithDiscrepancy : TransferStatus::Received,
                'received_by' => $by->id,
                'received_at' => now(),
            ])->save();

            if ($differences > 0) {
                $this->issues->open(
                    IssueType::TransferDiscrepancy,
                    IssueSeverity::Warning,
                    $transfer->to_branch_id,
                    $transfer,
                    trans_choice('Transfer :number arrived with :count difference|Transfer :number arrived with :count differences', $differences, ['number' => $transfer->number]),
                    ['transfer' => $transfer->number, 'differences' => $differences],
                );
            }

            return $transfer;
        });

        $this->refreshRequest($transfer);

        $this->notifier->toBranch('transfers.send', $transfer->from_branch_id, new DocumentEvent(
            'transfers.received',
            $transfer->status === TransferStatus::Received
                ? Msg::key('Transfer :number was received in :to')
                : Msg::key('Transfer :number was received in :to with differences'),
            ['number' => $transfer->number, 'to' => $transfer->toBranch->code],
            null,
            [],
            route('ops.transfers.show', $transfer),
            $transfer->status === TransferStatus::Received ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle',
        ), $by);

        return $transfer;
    }

    /**
     * Super Admin decision on a missing / short / extra line.
     */
    public function resolveLine(TransferItem $item, TransferLineResolution $resolution, User $by, ?string $note = null): TransferItem
    {
        $transfer = $item->transfer;
        $this->authorize($by, 'stock.adjust', $transfer->to_branch_id);

        DB::transaction(function () use ($item, $resolution, $by, $note, $transfer) {
            /** @var TransferItem $item */
            $item = TransferItem::query()->with(['stockUnit', 'fromBatch'])->lockForUpdate()->findOrFail($item->id);

            if ($item->resolution !== TransferLineResolution::None || $item->line_status === TransferLineStatus::Received) {
                throw new StockRuleViolation(__('This line is already settled.'));
            }

            $group = (string) Str::uuid();
            $outstanding = $item->outstanding();

            if ($item->line_status === TransferLineStatus::Extra) {
                // Scanned but not on the slip: acknowledge; the unit stays where the books say.
                if ($resolution !== TransferLineResolution::Found) {
                    throw new StockRuleViolation(__('An extra item can only be acknowledged. Move it with its own transfer if needed.'));
                }
            } elseif ($item->stock_unit_id !== null) {
                match ($resolution) {
                    TransferLineResolution::Found => $this->stock->receiveUnit($item->stockUnit, $transfer->toBranch, $by, $transfer, $group, 'stock.adjust'),
                    TransferLineResolution::ReturnedToSender => $this->stock->returnUnitToSender($item->stockUnit, $by, $transfer, $group, 'stock.adjust'),
                    TransferLineResolution::WrittenOff => $this->stock->moveUnitOut($item->stockUnit, StockStatus::Lost, MovementType::WriteOff, $by, $transfer, $group, 'stock.adjust', $note ?? __('Lost in transit')),
                    TransferLineResolution::None => throw new StockRuleViolation(__('Choose what happened.')),
                };

                if ($resolution === TransferLineResolution::Found) {
                    $item->quantity_received = 1;
                }
            } else {
                match ($resolution) {
                    TransferLineResolution::Found => $item->to_batch_id = $this->stock->receiveBatch($item->fromBatch, $outstanding, $transfer->toBranch, $by, $transfer, $group, 'stock.adjust')->id,
                    TransferLineResolution::ReturnedToSender => $this->stock->returnBatchToSender($item->fromBatch, $outstanding, $by, $transfer, $group, 'stock.adjust'),
                    TransferLineResolution::WrittenOff => $this->stock->recordLossInTransit($item->fromBatch, $outstanding, $by, $transfer, $group, $note ?? __('Lost in transit')),
                    TransferLineResolution::None => throw new StockRuleViolation(__('Choose what happened.')),
                };

                if ($resolution === TransferLineResolution::Found) {
                    $item->quantity_received = $item->quantity_sent;
                }
            }

            $item->forceFill(['resolution' => $resolution, 'resolved_by' => $by->id, 'resolved_at' => now(), 'override_reason' => $note ?? $item->override_reason])->save();
        });

        $transfer->refresh()->load('items');

        if (! $transfer->hasOpenDifferences()) {
            $this->issues->resolveFor($transfer, IssueType::TransferDiscrepancy, __('All differences settled.'), $by);
        }

        return $item->refresh();
    }

    /**
     * Only before receiving: a draft is just closed, a transfer in transit goes back to the sender.
     */
    public function cancel(Transfer $transfer, string $reason, User $by): Transfer
    {
        if (blank($reason)) {
            throw new StockRuleViolation(__('A reason is required.'), 'reason');
        }

        $this->authorize($by, 'transfers.cancel', $transfer->from_branch_id);

        $transfer = DB::transaction(function () use ($transfer, $reason, $by) {
            /** @var Transfer $transfer */
            $transfer = Transfer::query()->with(['items.stockUnit', 'items.fromBatch'])->lockForUpdate()->findOrFail($transfer->id);

            if (! in_array($transfer->status, [TransferStatus::Draft, TransferStatus::InTransit], true)) {
                throw new StockRuleViolation(__('A received transfer cannot be cancelled.'));
            }

            if ($transfer->status === TransferStatus::InTransit) {
                $group = (string) Str::uuid();

                foreach ($transfer->items as $item) {
                    $item->stock_unit_id !== null
                        ? $this->stock->returnUnitToSender($item->stockUnit, $by, $transfer, $group)
                        : $this->stock->returnBatchToSender($item->fromBatch, $item->quantity_sent, $by, $transfer, $group);
                }

                $this->assetTransfers->cancelAll($transfer, $by, $group);
            }

            $transfer->forceFill([
                'status' => TransferStatus::Cancelled,
                'cancelled_by' => $by->id,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ])->save();

            return $transfer;
        });

        $this->refreshRequest($transfer);

        return $transfer;
    }

    /**
     * Closing a branch (P2-9): everything on its shelves goes to another branch in one transfer.
     */
    public function transferAll(Branch $from, Branch $to, User $by, ?UploadedFile $photo = null): Transfer
    {
        return DB::transaction(function () use ($from, $to, $by, $photo) {
            $transfer = $this->createDraft($from, $to, $by, __('All stock moved before closing :branch.', ['branch' => $from->code]));

            StockUnit::query()->where('branch_id', $from->id)->where('status', StockStatus::InStock)->each(
                fn (StockUnit $unit) => $transfer->items()->create(['product_id' => $unit->product_id, 'stock_unit_id' => $unit->id, 'quantity_sent' => 1]),
            );

            StockBatch::query()->where('branch_id', $from->id)->where('location_type', LocationType::Shelf)->where('quantity', '>', 0)->each(
                fn (StockBatch $batch) => $transfer->items()->create(['product_id' => $batch->product_id, 'from_batch_id' => $batch->id, 'quantity_sent' => $batch->quantity]),
            );

            // Kits travel with everything in them; loose instruments too.
            Kit::query()->where('branch_id', $from->id)->where('status', KitStatus::Available)->each(
                fn (Kit $kit) => $this->assetTransfers->addKit($transfer, $kit, $by),
            );

            Instrument::query()->where('branch_id', $from->id)->whereNull('kit_id')->where('status', InstrumentStatus::Available)->each(
                fn (Instrument $instrument) => $this->assetTransfers->addInstrument($transfer, $instrument, $by),
            );

            if ($transfer->items()->doesntExist() && $transfer->assets()->doesntExist()) {
                throw new StockRuleViolation(__('There is no stock on the shelves of :branch.', ['branch' => $from->code]));
            }

            return $this->send($transfer, $by, __('Station closing: all stock moved'), $photo);
        });
    }

    /**
     * The photo of the items: ours before sending (draft, sender), theirs on arrival (in
     * transit, receiver). Both are required; photos are kept, never replaced or deleted.
     */
    public function attachPhoto(Transfer $transfer, UploadedFile $photo, AttachmentKind $kind, User $by): Attachment
    {
        [$permission, $branchId, $status] = match ($kind) {
            AttachmentKind::TransferSent => ['transfers.send', $transfer->from_branch_id, TransferStatus::Draft],
            AttachmentKind::TransferReceived => ['transfers.receive', $transfer->to_branch_id, TransferStatus::InTransit],
            default => throw new \InvalidArgumentException('Not a transfer photo.'),
        };

        $this->authorize($by, $permission, $branchId);

        if (Transfer::query()->whereKey($transfer->id)->toBase()->value('status') !== $status->value) {
            throw new StockRuleViolation($kind === AttachmentKind::TransferSent
                ? __('The photo before sending can only be added while the transfer is a draft.')
                : __('The photo on arrival can only be added while the transfer is on its way.'), 'photo');
        }

        if (! str_starts_with((string) $photo->getMimeType(), 'image/') || $photo->getSize() > self::PHOTO_MAX_BYTES) {
            throw new StockRuleViolation(__('The photo must be an image of at most 10 MB.'), 'photo');
        }

        $path = $photo->store('transfers/'.$transfer->id, 'local');

        $attachment = Attachment::query()->create([
            'attachable_type' => $transfer->getMorphClass(),
            'attachable_id' => $transfer->id,
            'kind' => $kind,
            'disk' => 'local',
            'path' => $path,
            'original_name' => mb_substr($photo->getClientOriginalName(), 0, 191),
            'mime' => $photo->getMimeType(),
            'size_bytes' => (int) $photo->getSize(),
            'uploaded_by' => $by->id,
        ]);

        $transfer->unsetRelation('attachments');

        return $attachment;
    }

    /**
     * When every transfer of a request has arrived, the request is fulfilled.
     */
    private function refreshRequest(Transfer $transfer): void
    {
        $request = $transfer->stockRequest;

        if ($request === null || ! in_array($request->status, [StockRequestStatus::Approved, StockRequestStatus::PartiallyApproved], true)) {
            return;
        }

        $open = $request->transfers()->whereIn('status', [TransferStatus::Draft, TransferStatus::InTransit])->exists();

        if (! $open && $request->transfers()->whereIn('status', [TransferStatus::Received, TransferStatus::ReceivedWithDiscrepancy])->exists()) {
            $request->forceFill(['status' => StockRequestStatus::Fulfilled])->save();
        }
    }

    private function assertDraft(Transfer $transfer): void
    {
        if ($transfer->status !== TransferStatus::Draft) {
            throw new StockRuleViolation(__('Transfer :number was already sent.', ['number' => $transfer->number]));
        }
    }

    /**
     * A unit waiting in a write-off (or on another open transfer) cannot travel.
     */
    private function assertNotReserved(StockUnit $unit, int $exceptTransferId): void
    {
        $inWriteOff = WriteOffItem::query()->where('stock_unit_id', $unit->id)
            ->whereHas('writeOff', fn ($q) => $q->where('status', WriteOffStatus::Pending))->exists();

        if ($inWriteOff) {
            throw new StockRuleViolation(__('Unit :serial is waiting in a write-off.', ['serial' => $unit->serial]), 'serial');
        }

        $onOther = TransferItem::query()->where('stock_unit_id', $unit->id)
            ->where('transfer_id', '<>', $exceptTransferId)
            ->whereHas('transfer', fn ($q) => $q->where('status', TransferStatus::Draft))
            ->exists();

        if ($onOther) {
            throw new StockRuleViolation(__('Unit :serial is on another transfer that was not sent yet.', ['serial' => $unit->serial]), 'serial');
        }
    }

    private function authorize(User $by, string $permission, Branch|int $branch): void
    {
        if (! $this->scope->allows($by, $permission, $branch instanceof Branch ? $branch->id : $branch)) {
            throw new AuthorizationException(__('You are not allowed to do this in station :code.', [
                'code' => $branch instanceof Branch ? $branch->code : (string) Branch::query()->whereKey($branch)->value('code'),
            ]));
        }
    }
}
