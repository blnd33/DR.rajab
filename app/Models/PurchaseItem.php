<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One line of a supplier invoice.
 *
 * @property int $id
 * @property int $quantity
 * @property int $quantity_received
 * @property string $unit_price
 * @property string $line_total
 * @property ?Carbon $expected_expiry
 * @property string $landed_cost_per_unit
 * @property string $true_unit_cost
 * @property int $purchase_id
 * @property int $product_id
 * @property ?string $expected_lot
 */
class PurchaseItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'quantity_received' => 'integer',
            'unit_price' => 'decimal:4',
            'line_total' => 'decimal:4',
            'expected_expiry' => DateOnly::class,
            'landed_cost_per_unit' => 'decimal:4',
            'true_unit_cost' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<StockUnit, $this> */
    public function units(): HasMany
    {
        return $this->hasMany(StockUnit::class);
    }

    /** @return HasMany<StockBatch, $this> */
    public function batches(): HasMany
    {
        return $this->hasMany(StockBatch::class);
    }
}
