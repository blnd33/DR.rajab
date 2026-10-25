<?php

namespace App\Livewire\Ops\Stock;

use App\Enums\StockStatus;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockOnHand;
use App\Models\StockUnit;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;
use App\Services\Stock\FefoPicker;
use App\Support\CurrentBranch;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * One product in the current branch: what to use first (FEFO), every unit / lot on hand,
 * and quantities in the other branches the user may see.
 */
#[Layout('layouts::ops')]
class ProductShow extends Component
{
    public Product $product;

    public function mount(Product $product): void
    {
        abort_unless(app(BranchScopeResolver::class)->hasAny($this->user(), 'stock.view'), 403);
        $this->product = $product;
    }

    public function render(): View
    {
        $branchId = CurrentBranch::id();
        $user = $this->user();
        $canHere = $branchId !== null && $user->canInBranch('stock.view', $branchId);
        $fefo = app(FefoPicker::class);

        $units = $canHere && $this->product->isSerialTracked()
            ? StockUnit::query()->with('location')->where('product_id', $this->product->id)->where('branch_id', $branchId)
                ->whereIn('status', StockStatus::onHand())
                ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')->orderBy('expiry_date')->orderBy('id')
                ->limit(100)->get()
            : collect();

        $batches = $canHere && ! $this->product->isSerialTracked()
            ? StockBatch::query()->with('location')->where('product_id', $this->product->id)->where('branch_id', $branchId)
                ->where('quantity', '>', 0)
                ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')->orderBy('expiry_date')->orderBy('id')
                ->get()
            : collect();

        $elsewhere = app(BranchScopeResolver::class)->apply(StockOnHand::query()->with('branch'), $user, 'stock.view')
            ->where('product_id', $this->product->id)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', '<>', $branchId))
            ->orderBy('branch_id')
            ->get();

        return view('livewire.ops.stock.product', [
            'branch' => CurrentBranch::get(),
            'next' => $canHere && $branchId !== null ? $fefo->suggest($this->product, $branchId) : null,
            'units' => $units,
            'batches' => $batches,
            'onShelf' => $this->product->isSerialTracked() ? $units->where('status', StockStatus::InStock)->count() : (int) $batches->where('location_type.value', 'shelf')->sum('quantity'),
            'min' => $branchId !== null ? $this->product->minStockFor($branchId) : $this->product->min_stock_default,
            'elsewhere' => $elsewhere,
        ])->title($this->product->sku);
    }

    private function user(): User
    {
        /** @var User */
        return Auth::user();
    }
}
