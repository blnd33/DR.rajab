<?php

namespace App\Livewire\Ops\Movement;

use App\Enums\CountLineResult;
use App\Enums\StockCountStatus;
use App\Livewire\Ops\Concerns\RunsStockActions;
use App\Models\StockCount;
use App\Services\Movement\StockCountService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Counting on the phone. Scans go to the phone's queue first (works offline) and are synced
 * to /app/sync/count-scans; lot quantities are typed. Submit sends the count for review.
 */
#[Layout('layouts::ops')]
class CountShow extends Component
{
    use RunsStockActions;

    public StockCount $count;

    /** @var array<int, int|string> lot line id → counted */
    public array $lotCounts = [];

    public string $filter = 'open';

    public function mount(StockCount $count): void
    {
        abort_unless($this->user()->canInBranch('stock_counts.view_any', $count->branch_id) || $this->user()->canInBranch('stock_counts.scan', $count->branch_id), 403);
        $this->count = $count;
    }

    public function saveLotCount(int $lineId, StockCountService $counts): void
    {
        $line = $this->count->lines()->findOrFail($lineId);
        $value = (int) ($this->lotCounts[$lineId] ?? 0);

        $this->attempt(fn () => $counts->setCounted($line, $value, $this->user()));
    }

    public function submit(StockCountService $counts): void
    {
        $done = $this->attempt(fn () => $counts->submit($this->count, $this->user()), __('Count submitted for review.'));

        if ($done !== null) {
            $this->count->refresh();
        }
    }

    public function render(): View
    {
        $lines = $this->count->lines()->with(['product', 'stockUnit', 'stockBatch', 'foundBranch'])->orderBy('product_id')->get();

        // Always from the database: scans synced from the queue change these numbers too.
        foreach ($lines->whereNotNull('stock_batch_id') as $line) {
            $this->lotCounts[$line->id] = $line->counted_qty;
        }

        $units = $lines->whereNotNull('stock_unit_id')->where('expected_qty', 1);
        $visible = match ($this->filter) {
            'open' => $lines->filter(fn ($l) => $l->stock_unit_id === null || $l->counted_qty < $l->expected_qty || $l->result !== null && $l->result !== CountLineResult::Found),
            default => $lines,
        };

        return view('livewire.ops.movement.count-show', [
            'lines' => $visible,
            'unitsFound' => $units->where('counted_qty', 1)->count(),
            'unitsExpected' => $units->count(),
            'extras' => $lines->whereIn('result', [CountLineResult::ExtraOtherBranch, CountLineResult::ExtraUnknown])->count(),
            'canScan' => $this->count->status === StockCountStatus::InProgress && $this->user()->canInBranch('stock_counts.scan', $this->count->branch_id),
        ])->title($this->count->number);
    }
}
