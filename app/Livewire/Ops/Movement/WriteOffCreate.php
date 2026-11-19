<?php

namespace App\Livewire\Ops\Movement;

use App\Enums\LocationType;
use App\Enums\ScanContext;
use App\Enums\WriteOffReason;
use App\Livewire\Ops\Concerns\RunsStockActions;
use App\Models\StockBatch;
use App\Services\Barcode\ScanResolver;
use App\Services\Barcode\ScanResultKind;
use App\Services\Movement\WriteOffService;
use App\Support\CurrentBranch;
use App\Support\Locale\DigitConverter;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Report damaged / lost / expired items (Module 15): scan them, choose the reason, add a
 * note and photos. Nothing leaves stock until an approver accepts it.
 */
#[Layout('layouts::ops')]
class WriteOffCreate extends Component
{
    use RunsStockActions;
    use WithFileUploads;

    /** @var array<string, array{unit_id: ?int, batch_id: ?int, quantity: int, label: string, code: string, max: int}> */
    public array $items = [];

    public string $reason = 'damaged';

    public string $note = '';

    /** @var array<int, mixed> */
    public array $photos = [];

    public function mount(): void
    {
        abort_unless($this->user()->canInBranch('write_offs.report', CurrentBranch::id()), 403);
    }

    public function handleScan(string $raw, string $source = 'manual'): void
    {
        $raw = trim((string) DigitConverter::toWestern($raw));
        $branchId = (int) CurrentBranch::id();
        $result = app(ScanResolver::class)->resolve($raw, ScanContext::Lookup, $this->user(), $branchId);

        if ($result->kind === ScanResultKind::Unit && $result->unit !== null) {
            $unit = $result->unit;

            if ($unit->branch_id !== $branchId || ! $unit->status->isOnHand()) {
                $this->scanFailed(__('Unit :serial is not in stock in this station.', ['serial' => $unit->serial]));

                return;
            }

            $this->items['u'.$unit->id] = ['unit_id' => $unit->id, 'batch_id' => null, 'quantity' => 1, 'label' => $unit->product->name, 'code' => $unit->serial, 'max' => 1];
            $this->dispatch('scan-feedback', type: 'success');

            return;
        }

        if ($result->product !== null && ! $result->product->isSerialTracked()) {
            /** @var StockBatch|null $batch */
            $batch = $result->batches->where('branch_id', $branchId)->filter(fn (StockBatch $b) => $b->location_type === LocationType::Shelf)->sortBy('expiry_date')->first();

            if ($batch === null) {
                $this->scanFailed(__('No :sku on the shelf in this station.', ['sku' => $result->product->sku]));

                return;
            }

            $key = 'b'.$batch->id;
            $current = $this->items[$key]['quantity'] ?? 0;
            $this->items[$key] = ['unit_id' => null, 'batch_id' => $batch->id, 'quantity' => min($batch->quantity, $current + 1), 'label' => $result->product->name, 'code' => $batch->lot ?? '—', 'max' => $batch->quantity];
            $this->dispatch('scan-feedback', type: 'success');

            return;
        }

        $this->scanFailed(__('Code not recognised.'));
    }

    public function change(string $key, int $by): void
    {
        if (isset($this->items[$key]) && $this->items[$key]['batch_id'] !== null) {
            $this->items[$key]['quantity'] = max(1, min($this->items[$key]['max'], $this->items[$key]['quantity'] + $by));
        }
    }

    public function remove(string $key): void
    {
        unset($this->items[$key]);
    }

    public function submit(WriteOffService $writeOffs): void
    {
        $this->validate(['photos.*' => ['image', 'max:8192']]);
        $branch = CurrentBranch::get();

        if ($branch === null) {
            return;
        }

        $done = $this->attempt(fn () => $writeOffs->report(
            $branch,
            WriteOffReason::tryFrom($this->reason) ?? WriteOffReason::Damaged,
            array_map(fn ($i) => ['stock_unit_id' => $i['unit_id'], 'stock_batch_id' => $i['batch_id'], 'quantity' => $i['quantity']], array_values($this->items)),
            $this->user(),
            $this->note ?: null,
            $this->photos,
        ), __('Reported. It waits for approval.'));

        if ($done !== null) {
            $this->redirectRoute('ops.tasks', navigate: true);
        }
    }

    public function render(): View
    {
        return view('livewire.ops.movement.write-off-create')->title(__('Report damaged / lost'));
    }

    private function scanFailed(string $message): void
    {
        $this->dispatch('scan-feedback', type: 'error');
        $this->dispatch('toast', type: 'error', message: $message);
    }
}
