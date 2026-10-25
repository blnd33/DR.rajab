<?php

namespace App\Services\Stock;

use App\Enums\LocationType;
use App\Enums\StockStatus;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockUnit;
use App\Services\Expiry\ExpiryClassifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * First-Expired-First-Out: whenever stock is picked or suggested, the soonest-expiring
 * valid item comes first. Expired items (and levels marked "blocks usage") are skipped;
 * items without an expiry date come last.
 */
class FefoPicker
{
    public function __construct(private readonly ExpiryClassifier $expiry) {}

    /**
     * Serial units on the shelf in a branch, soonest valid expiry first.
     *
     * @return Collection<int, StockUnit>
     */
    public function units(Product $product, int $branchId, int $limit = 10): Collection
    {
        $query = StockUnit::query()
            ->with('location')
            ->where('product_id', $product->id)
            ->where('branch_id', $branchId)
            ->where('status', StockStatus::InStock);

        return $this->orderFefo($this->excludeBlocked($query))->limit($limit)->get();
    }

    /**
     * Lot buckets on the shelf in a branch, soonest valid expiry first.
     *
     * @return Collection<int, StockBatch>
     */
    public function batches(Product $product, int $branchId): Collection
    {
        $query = StockBatch::query()
            ->with('location')
            ->where('product_id', $product->id)
            ->where('branch_id', $branchId)
            ->where('location_type', LocationType::Shelf)
            ->where('quantity', '>', 0);

        return $this->orderFefo($this->excludeBlocked($query))->get();
    }

    /**
     * Split a quantity over lot buckets, FEFO. Returns what can be taken; the caller checks
     * whether the total covers the request.
     *
     * @return list<array{batch: StockBatch, quantity: int}>
     */
    public function allocate(Product $product, int $branchId, int $quantity): array
    {
        $allocation = [];
        $remaining = $quantity;

        foreach ($this->batches($product, $branchId) as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($remaining, $batch->quantity);
            $allocation[] = ['batch' => $batch, 'quantity' => $take];
            $remaining -= $take;
        }

        return $allocation;
    }

    /**
     * The single best suggestion (unit or lot bucket), or null when nothing valid is on the shelf.
     */
    public function suggest(Product $product, int $branchId): StockUnit|StockBatch|null
    {
        return $product->isSerialTracked()
            ? $this->units($product, $branchId, 1)->first()
            : $this->batches($product, $branchId)->first();
    }

    /**
     * @template TModel of StockUnit|StockBatch
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function excludeBlocked(Builder $query): Builder
    {
        $blockedThrough = $this->expiry->blockedThrough()->toDateString();

        return $query->where(fn (Builder $q) => $q->whereNull('expiry_date')->orWhere('expiry_date', '>', $blockedThrough));
    }

    /**
     * Soonest expiry first, no-expiry last, then oldest received.
     *
     * @template TModel of StockUnit|StockBatch
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function orderFefo(Builder $query): Builder
    {
        return $query
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expiry_date')
            ->orderBy('id');
    }
}
