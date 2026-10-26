<?php

namespace App\Livewire\Ops\Movement;

use App\Enums\StockCountScope;
use App\Livewire\Ops\Concerns\RunsStockActions;
use App\Models\BranchLocation;
use App\Models\Category;
use App\Services\Movement\StockCountService;
use App\Support\CurrentBranch;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Start a stock count in the current branch: whole branch, one category or one shelf.
 */
#[Layout('layouts::ops')]
class CountStart extends Component
{
    use RunsStockActions;

    public string $scope = 'full';

    public ?int $categoryId = null;

    public ?int $locationId = null;

    public function mount(): void
    {
        abort_unless($this->user()->canInBranch('stock_counts.start', CurrentBranch::id()), 403);
    }

    public function start(StockCountService $counts): void
    {
        $branch = CurrentBranch::get();

        if ($branch === null) {
            return;
        }

        $count = $this->attempt(fn () => $counts->start(
            $branch,
            StockCountScope::tryFrom($this->scope) ?? StockCountScope::Full,
            $this->user(),
            $this->categoryId,
            $this->locationId,
        ));

        if ($count !== null) {
            $this->redirectRoute('ops.counts.show', $count, navigate: true);
        }
    }

    public function render(): View
    {
        return view('livewire.ops.movement.count-start', [
            'branch' => CurrentBranch::get(),
            'categories' => Category::options(),
            'locations' => BranchLocation::query()->active()->where('branch_id', CurrentBranch::id())->orderBy('code')->get(),
        ])->title(__('Count stock'));
    }
}
