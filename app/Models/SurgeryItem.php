<?php

namespace App\Models;

use App\Enums\SurgeryItemSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One implant (serial) or lot consumable used in a surgery.
 *
 * @property int $id
 * @property int $surgery_id
 * @property int $product_id
 * @property ?int $stock_unit_id
 * @property ?int $stock_batch_id
 * @property int $quantity
 * @property SurgeryItemSource $source
 * @property ?int $kit_id
 * @property ?string $unit_cost
 * @property ?string $cost_currency
 * @property ?list<string> $warnings
 * @property ?string $override_reason
 * @property ?string $scanned_raw
 * @property ?Carbon $used_at
 * @property ?Carbon $reversed_at
 * @property ?string $reversal_reason
 */
class SurgeryItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'source' => SurgeryItemSource::class,
            'unit_cost' => 'decimal:4',
            'warnings' => 'array',
            'used_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Surgery, $this> */
    public function surgery(): BelongsTo
    {
        return $this->belongsTo(Surgery::class);
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

    /** @return BelongsTo<Kit, $this> */
    public function kit(): BelongsTo
    {
        return $this->belongsTo(Kit::class);
    }

    public function code(): string
    {
        return $this->stockUnit->serial ?? ($this->stockBatch->lot ?? '—');
    }
}
