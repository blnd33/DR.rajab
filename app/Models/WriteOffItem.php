<?php

namespace App\Models;

use App\Enums\StockStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $write_off_id
 * @property int $product_id
 * @property ?int $stock_unit_id
 * @property ?int $stock_batch_id
 * @property int $quantity
 * @property StockStatus $target_status
 * @property string $unit_cost
 * @property string $cost_currency
 */
class WriteOffItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'target_status' => StockStatus::class,
            'unit_cost' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<WriteOff, $this> */
    public function writeOff(): BelongsTo
    {
        return $this->belongsTo(WriteOff::class);
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

    public function code(): string
    {
        return $this->stockUnit->serial ?? $this->stockBatch->lot ?? '—';
    }
}
