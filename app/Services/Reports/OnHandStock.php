<?php

namespace App\Services\Reports;

use App\Enums\StockStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockUnit;
use Brick\Math\BigDecimal;

/**
 * What is on hand now, per station and item (serial units + lot quantities: shelves, instrument
 * sets, at hospitals, in transit), with its value at true cost in the report currency, and each
 * item's specialty, category and subcategory (the top two levels of its category tree). Shared
 * by the Stock screens.
 */
class OnHandStock
{
    public function __construct(private readonly SnapshotConverters $fx) {}

    /**
     * @return list<array{branch_id: int, product_id: int, quantity: int, value: BigDecimal}>
     */
    public function lines(ReportContext $context): array
    {
        $units = StockUnit::query()
            ->whereIn('status', StockStatus::onHand())
            ->when($context->branchIds !== null, fn ($q) => $q->whereIn('branch_id', $context->branchIds))
            ->groupBy('branch_id', 'product_id', 'cost_currency', 'cost_fx_snapshot_id')
            ->selectRaw('branch_id, product_id, cost_currency, cost_fx_snapshot_id, COUNT(*) AS quantity, SUM(unit_cost) AS total')
            ->toBase()->get();

        $batches = StockBatch::query()
            ->where('quantity', '>', 0)
            ->when($context->branchIds !== null, fn ($q) => $q->whereIn('branch_id', $context->branchIds))
            ->groupBy('branch_id', 'product_id', 'cost_currency', 'cost_fx_snapshot_id')
            ->selectRaw('branch_id, product_id, cost_currency, cost_fx_snapshot_id, SUM(quantity) AS quantity, SUM(quantity * unit_cost) AS total')
            ->toBase()->get();

        $lines = [];

        foreach ($units->concat($batches) as $row) {
            $key = $row->branch_id.'-'.$row->product_id;
            $value = $this->fx->convert((string) $row->total, $row->cost_currency, $context->currency, $row->cost_fx_snapshot_id !== null ? (int) $row->cost_fx_snapshot_id : null, $context) ?? BigDecimal::zero();

            $lines[$key] ??= ['branch_id' => (int) $row->branch_id, 'product_id' => (int) $row->product_id, 'quantity' => 0, 'value' => BigDecimal::zero()];
            $lines[$key]['quantity'] += (int) $row->quantity;
            $lines[$key]['value'] = $lines[$key]['value']->plus($value);
        }

        return array_values($lines);
    }

    /**
     * Each product's SKU, name, specialty (the nearest one up its category tree), category (the top
     * of the tree) and subcategory / items group (the level under it).
     *
     * @param  iterable<int>  $productIds
     * @return array<int, array{sku: string, name: string, specialty: string, category: string, subcategory: string}>
     */
    public function items(iterable $productIds): array
    {
        $products = Product::query()->whereIn('id', collect($productIds)->unique()->values())->get(['id', 'sku', 'name', 'category_id']);
        $categories = Category::query()->get(['id', 'parent_id', 'name', 'specialty'])->keyBy('id');
        $items = [];

        foreach ($products as $product) {
            // The category's path from the top: [category, subcategory, …].
            $path = [];
            $specialty = null;
            $category = $categories->get($product->category_id);
            $guard = 0;

            while ($category !== null && $guard++ < 20) {
                array_unshift($path, (string) $category->name);
                $specialty ??= $category->specialty;
                $category = $category->parent_id !== null ? $categories->get($category->parent_id) : null;
            }

            $items[$product->id] = [
                'sku' => (string) $product->sku,
                'name' => (string) $product->name,
                'specialty' => $specialty?->getLabel() ?? __('No specialty'),
                'category' => $path[0] ?? __('No category'),
                'subcategory' => $path[1] ?? '—',
            ];
        }

        return $items;
    }
}
