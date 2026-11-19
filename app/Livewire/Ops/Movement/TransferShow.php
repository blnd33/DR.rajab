<?php

namespace App\Livewire\Ops\Movement;

use App\Enums\AttachmentKind;
use App\Enums\TransferStatus;
use App\Livewire\Ops\Concerns\RunsStockActions;
use App\Models\StockUnit;
use App\Models\Transfer;
use App\Models\TransferItem;
use App\Services\Barcode\ScanResolver;
use App\Services\Movement\TransferService;
use App\Support\Locale\DigitConverter;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * One transfer on the phone. Draft: scan items in and send. In transit (receiving branch):
 * scan every unit, confirm lot quantities, receive. Otherwise: the slip with line results.
 */
#[Layout('layouts::ops')]
class TransferShow extends Component
{
    use RunsStockActions;
    use WithFileUploads;

    public Transfer $transfer;

    /** @var list<int> units scanned at the receiver */
    public array $scanned = [];

    /** @var list<string> codes scanned at the receiver that are not on the slip */
    public array $extra = [];

    /** @var array<int, int> lot line id → quantity received */
    public array $lotQty = [];

    public string $overrideReason = '';

    public string $cancelReason = '';

    /** Photo of the items: ours before sending, theirs on arrival (required for both). */
    #[Validate('nullable|image|max:10240')]
    public ?TemporaryUploadedFile $photo = null;

    public function mount(Transfer $transfer): void
    {
        $user = $this->user();
        abort_unless(
            $user->canInBranch('transfers.view_any', $transfer->from_branch_id) || $user->canInBranch('transfers.view_any', $transfer->to_branch_id)
            || $user->canInBranch('transfers.receive', $transfer->to_branch_id) || $user->canInBranch('transfers.send', $transfer->from_branch_id),
            403,
        );

        $this->transfer = $transfer;

        foreach ($transfer->items()->whereNull('stock_unit_id')->get() as $item) {
            $this->lotQty[$item->id] = $item->quantity_sent;
        }
    }

    public function handleScan(string $raw, string $source = 'manual'): void
    {
        $raw = trim((string) DigitConverter::toWestern($raw));

        if ($raw === '') {
            return;
        }

        if ($this->canEdit()) {
            $this->attempt(fn () => app(TransferService::class)->addByScan($this->transfer, $raw, $this->user()), scan: true);

            return;
        }

        if (! $this->canReceive()) {
            return;
        }

        $item = $this->transfer->items()->whereHas('stockUnit', fn ($q) => $q->where('serial', $raw))->first()
            ?? $this->unitItemFromScan($raw);

        if ($item !== null) {
            if (! in_array($item->stock_unit_id, $this->scanned, true)) {
                $this->scanned[] = $item->stock_unit_id;
            }

            $this->dispatch('scan-feedback', type: 'success');

            return;
        }

        if (! in_array($raw, $this->extra, true)) {
            $this->extra[] = $raw;
        }

        $this->dispatch('scan-feedback', type: 'error');
        $this->dispatch('toast', type: 'warning', message: __(':code is not on this transfer.', ['code' => $raw]));
    }

    public function updatedPhoto(): void
    {
        $this->validateOnly('photo');

        if ($this->photo === null) {
            return;
        }

        $kind = $this->transfer->status === TransferStatus::Draft ? AttachmentKind::TransferSent : AttachmentKind::TransferReceived;
        $this->attempt(fn () => app(TransferService::class)->attachPhoto($this->transfer, $this->photo, $kind, $this->user()), __('Photo saved.'));
        $this->photo = null;
    }

    public function forgetExtra(string $code): void
    {
        $this->extra = array_values(array_diff($this->extra, [$code]));
    }

    public function changeQuantity(int $itemId, int $by): void
    {
        $item = $this->transfer->items()->findOrFail($itemId);
        $this->attempt(fn () => app(TransferService::class)->setBatchQuantity($item, $item->quantity_sent + $by, $this->user()));
    }

    public function removeItem(int $itemId): void
    {
        $item = $this->transfer->items()->findOrFail($itemId);
        $this->attempt(fn () => app(TransferService::class)->removeItem($item, $this->user()));
    }

    public function send(): void
    {
        $done = $this->attempt(
            fn () => app(TransferService::class)->send($this->transfer, $this->user(), $this->overrideReason ?: null),
            __('Transfer sent. The receiving station has been told.'),
        );

        if ($done !== null) {
            $this->transfer->refresh();
        }
    }

    public function receive(): void
    {
        $done = $this->attempt(
            fn () => app(TransferService::class)->receive($this->transfer, $this->scanned, array_map('intval', $this->lotQty), $this->user(), $this->extra),
        );

        if ($done !== null) {
            $this->transfer->refresh();
            $this->dispatch('toast', type: $done->status === TransferStatus::Received ? 'success' : 'warning', message: $done->status === TransferStatus::Received
                ? __('Received. Everything matched.')
                : __('Received with differences. The admin will settle them.'));
        }
    }

    public function cancel(): void
    {
        $done = $this->attempt(fn () => app(TransferService::class)->cancel($this->transfer, $this->cancelReason, $this->user()), __('Transfer cancelled.'));

        if ($done !== null) {
            $this->transfer->refresh();
        }
    }

    public function render(): View
    {
        $this->transfer->load(['fromBranch', 'toBranch', 'items.product', 'items.stockUnit', 'items.fromBatch', 'stockRequest.items.product', 'sender', 'receiver', 'attachments.uploader']);

        $units = $this->transfer->items->whereNotNull('stock_unit_id');

        return view('livewire.ops.movement.transfer-show', [
            'canEdit' => $this->canEdit(),
            'canSend' => $this->transfer->status === TransferStatus::Draft && $this->user()->canInBranch('transfers.send', $this->transfer->from_branch_id),
            'takeSent' => $this->transfer->status === TransferStatus::Draft && $this->user()->canInBranch('transfers.send', $this->transfer->from_branch_id) && $this->transfer->items->isNotEmpty(),
            'takeReceived' => $this->canReceive(),
            'sentPhoto' => $this->transfer->photo(AttachmentKind::TransferSent),
            'receivedPhoto' => $this->transfer->photo(AttachmentKind::TransferReceived),
            'canReceive' => $this->canReceive(),
            'canCancel' => in_array($this->transfer->status, [TransferStatus::Draft, TransferStatus::InTransit], true)
                && $this->user()->canInBranch('transfers.cancel', $this->transfer->from_branch_id),
            'canOverride' => $this->user()->canInBranch('stock.override_expired', $this->transfer->from_branch_id),
            'hasExpired' => $this->transfer->items->contains(fn (TransferItem $i) => ($i->stockUnit ?? $i->fromBatch)?->isBlockedByExpiry()),
            'unitsScanned' => $units->whereIn('stock_unit_id', $this->scanned)->count(),
            'unitsTotal' => $units->count(),
            'requested' => $this->requestedLines(),
        ])->title($this->transfer->number);
    }

    private function canEdit(): bool
    {
        return $this->transfer->status === TransferStatus::Draft && $this->user()->canInBranch('transfers.create', $this->transfer->from_branch_id);
    }

    private function canReceive(): bool
    {
        return $this->transfer->status === TransferStatus::InTransit && $this->user()->canInBranch('transfers.receive', $this->transfer->to_branch_id);
    }

    /**
     * A GS1 / internal label scan of a unit on this transfer.
     */
    private function unitItemFromScan(string $raw): ?TransferItem
    {
        $parsed = app(ScanResolver::class)->parse($raw);
        $serial = $parsed->serial ?? $parsed?->code;

        if ($serial === null) {
            return null;
        }

        $unitId = StockUnit::query()->where('serial', $serial)->value('id');

        return $unitId === null ? null : $this->transfer->items()->where('stock_unit_id', $unitId)->first();
    }

    /**
     * For a transfer made from a stock request: what was approved vs what is on the slip.
     *
     * @return list<array{sku: string, name: string, approved: int, on_slip: int}>
     */
    private function requestedLines(): array
    {
        $request = $this->transfer->stockRequest;

        if ($request === null) {
            return [];
        }

        return $request->items->where('transfer_id', $this->transfer->id)->map(fn ($line) => [
            'sku' => $line->product->sku,
            'name' => $line->product->name,
            'approved' => (int) $line->quantity_approved,
            'on_slip' => (int) $this->transfer->items->where('product_id', $line->product_id)->sum('quantity_sent'),
        ])->values()->all();
    }
}
