<?php

namespace App\Services\Clinical;

use App\Enums\StockStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockUnit;
use App\Support\Locale\Display;
use Illuminate\Support\Collection;

/**
 * Choosing what was used in a surgery from a list instead of scanning: a category (Knee, Hip…;
 * a parent such as Implants includes everything under it) → the products of that category with
 * stock in the branch → for a serial item the serial, for a lot item the quantity.
 */
class SurgeryProductPicker
{
    /**
     * id => "Implants › Knee".
     *
     * @return array<int, string>
     */
    public function categories(): array
    {
        return Category::options();
    }

    /**
     * The category and every category under it.
     *
     * @return list<int>
     */
    public function withChildren(int $categoryId): array
    {
        $byParent = Category::query()->get(['id', 'parent_id'])->groupBy('parent_id');
        $ids = [];
        $queue = [$categoryId];

        while ($queue !== [] && count($ids) < 500) {
            $id = array_shift($queue);
            $ids[] = $id;

            foreach ($byParent->get($id, collect()) as $child) {
                $queue[] = (int) $child->id;
            }
        }

        return $ids;
    }

    /**
     * Products with stock in the branch, of a category (and its children) when given.
     *
     * @return Collection<int, Product>
     */
    public function products(int $branchId, ?int $categoryId = null, ?string $search = null, int $limit = 50): Collection
    {
        $withUnits = StockUnit::query()->where('branch_id', $branchId)->where('status', StockStatus::InStock)->select('product_id');
        $withLots = StockBatch::query()->where('branch_id', $branchId)->onHand()->select('product_id');

        return Product::query()->active()
            ->where(fn ($q) => $q->whereIn('id', $withUnits)->orWhereIn('id', $withLots))
            ->when($categoryId !== null, fn ($q) => $q->whereIn('category_id', $this->withChildren($categoryId)))
            ->when(filled($search), fn ($q) => $q->search($search))
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    /**
     * id => "SKU · name" for a select.
     *
     * @return array<int, string>
     */
    public function productOptions(int $branchId, ?int $categoryId = null, ?string $search = null): array
    {
        return $this->products($branchId, $categoryId, $search)->mapWithKeys(fn (Product $p) => [$p->id => $p->label()])->all();
    }

    /**
     * In-stock serials of a product in the branch, first to expire first.
     *
     * @return Collection<int, StockUnit>
     */
    public function units(int $branchId, int $productId): Collection
    {
        return StockUnit::query()->where('branch_id', $branchId)->where('product_id', $productId)->where('status', StockStatus::InStock)
            ->orderByRaw('expiry_date IS NULL')->orderBy('expiry_date')->orderBy('serial')
            ->limit(200)->get();
    }

    /**
     * id => "serial · expiry" for a select.
     *
     * @return array<int, string>
     */
    public function unitOptions(int $branchId, int $productId): array
    {
        return $this->units($branchId, $productId)
            ->mapWithKeys(fn (StockUnit $u) => [$u->id => trim($u->serial.($u->expiry_date !== null ? ' · '.Display::date($u->expiry_date) : ''))])
            ->all();
    }

    /**
     * Everything the phone form needs to pick without a connection: categories (with their
     * children) and the branch's products with their serials.
     *
     * @return array{categories: list<array{id: int, label: string, ids: list<int>}>, products: list<array<string, mixed>>}
     */
    public function catalog(int $branchId): array
    {
        $categories = [];

        foreach ($this->categories() as $id => $label) {
            $categories[] = ['id' => (int) $id, 'label' => $label, 'ids' => $this->withChildren((int) $id)];
        }

        $products = $this->products($branchId, limit: 1000);
        $units = StockUnit::query()->where('branch_id', $branchId)->where('status', StockStatus::InStock)
            ->whereIn('product_id', $products->pluck('id'))
            ->orderByRaw('expiry_date IS NULL')->orderBy('expiry_date')->orderBy('serial')
            ->get(['id', 'product_id', 'serial', 'expiry_date'])->groupBy('product_id');

        return [
            'categories' => $categories,
            'products' => $products->map(fn (Product $p) => [
                'id' => $p->id,
                'label' => $p->label(),
                'category_id' => $p->category_id,
                'serial' => $p->isSerialTracked(),
                'units' => $units->get($p->id, collect())->map(fn (StockUnit $u) => [
                    'serial' => $u->serial,
                    'label' => trim($u->serial.($u->expiry_date !== null ? ' · '.Display::date($u->expiry_date) : '')),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
