<?php

namespace App\Models;

use App\Enums\CountLineResolution;
use App\Enums\CountLineResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $stock_count_id
 * @property int $product_id
 * @property ?int $stock_unit_id
 * @property ?int $stock_batch_id
 * @property int $expected_qty
 * @property int $counted_qty
 * @property ?CountLineResult $result
 * @property ?int $found_branch_id
 * @property CountLineResolution $resolution
 * @property ?string $note
 */
class StockCountLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'expected_qty' => 'integer',
            'counted_qty' => 'integer',
            'result' => CountLineResult::class,
            'resolution' => CountLineResolution::class,
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<StockCount, $this> */
    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<StockUnit, $this> */
    public function stockUnit(): BelongsTo
    {
        return $this->belongsTo(StockUnit::class);
    }

    /** @return BelongsTo<StockBatch, $this> */
    public function stockBatch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function foundBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'found_branch_id');
    }

    public function code(): string
    {
        return $this->stockUnit->serial ?? $this->stockBatch->lot ?? '—';
    }

    public function difference(): int
    {
        return $this->counted_qty - $this->expected_qty;
    }
}
