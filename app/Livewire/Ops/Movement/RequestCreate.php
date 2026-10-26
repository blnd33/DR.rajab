<?php

namespace App\Livewire\Ops\Movement;

use App\Enums\RequestPriority;
use App\Livewire\Ops\Concerns\RunsStockActions;
use App\Models\Branch;
use App\Models\LowStock;
use App\Models\Product;
use App\Services\Movement\StockRequestService;
use App\Support\CurrentBranch;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Ask another branch (or head office) for stock. "Fill from low stock" adds every product
 * below its minimum with the missing quantity (Module 17 one-click request).
 */
#[Layout('layouts::ops')]
class RequestCreate extends Component
{
    use RunsStockActions;

    /** @var array<int, int> product id → quantity */
    public array $lines = [];

    public ?int $sourceBranchId = null;

    public string $priority = 'normal';

    public ?string $neededBy = null;

    public string $notes = '';

    public string $search = '';

    #[Url]
    public bool $low = false;

    #[Url]
    public ?int $product = null;

    public function mount(): void
    {
        abort_unless($this->user()->canInBranch('stock_requests.create', CurrentBranch::id()), 403);

        if ($this->low) {
            $this->fillFromLowStock();
        }

        if ($this->product !== null && Product::query()->active()->whereKey($this->product)->exists()) {
            $this->lines[$this->product] = max(1, (int) ($this->lines[$this->product] ?? 1));
        }
    }

    public function fillFromLowStock(): void
    {
        foreach (LowStock::query()->where('branch_id', CurrentBranch::id())->get() as $row) {
            $this->lines[$row->product_id] = max((int) ($this->lines[$row->product_id] ?? 0), $row->reorder_qty ?? $row->shortage);
        }
    }

    public function add(int $productId): void
    {
        $this->lines[$productId] = (int) ($this->lines[$productId] ?? 0) + 1;
        $this->search = '';
    }

    public function change(int $productId, int $by): void
    {
        $quantity = (int) ($this->lines[$productId] ?? 0) + $by;

        if ($quantity <= 0) {
            unset($this->lines[$productId]);
        } else {
            $this->lines[$productId] = $quantity;
        }
    }

    public function submit(StockRequestService $requests): void
    {
        $branch = CurrentBranch::get();

        if ($branch === null) {
            return;
        }

        $request = $this->attempt(fn () => $requests->create(
            $branch,
            collect($this->lines)->map(fn ($qty, $productId) => ['product_id' => (int) $productId, 'quantity' => (int) $qty])->values()->all(),
            $this->user(),
            $this->sourceBranchId ? Branch::query()->find($this->sourceBranchId) : null,
            RequestPriority::tryFrom($this->priority) ?? RequestPriority::Normal,
            $this->neededBy ?: null,
            $this->notes ?: null,
        ), __('Request sent for approval.'));

        if ($request !== null) {
            $this->redirectRoute('ops.requests.show', $request, navigate: true);
        }
    }

    public function render(): View
    {
        $branchId = (int) CurrentBranch::id();

        return view('livewire.ops.movement.request-create', [
            'branch' => CurrentBranch::get(),
            'branches' => Branch::query()->active()->where('id', '<>', $branchId)->orderBy('code')->get(),
            'products' => Product::query()->whereIn('id', array_keys($this->lines))->get()->keyBy('id'),
            'matches' => mb_strlen(trim($this->search)) >= 2 ? Product::query()->active()->search($this->search)->orderBy('sku')->limit(8)->get() : collect(),
            'lowCount' => LowStock::query()->where('branch_id', $branchId)->count(),
        ])->title(__('Request stock'));
    }
}
