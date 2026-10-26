<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The share of a landed cost on one purchase line.
 *
 * @property int $id
 * @property string $amount
 * @property string $per_unit_amount
 * @property string $rate_used
 * @property int $quantity_on_hand
 * @property int $quantity_used
 */
class LandedCostAllocation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'per_unit_amount' => 'decimal:4',
            'rate_used' => 'decimal:8',
            'quantity_on_hand' => 'integer',
            'quantity_used' => 'integer',
        ];
    }

    /** @return BelongsTo<LandedCost, $this> */
    public function landedCost(): BelongsTo
    {
        return $this->belongsTo(LandedCost::class);
    }

    /** @return BelongsTo<PurchaseItem, $this> */
    public function purchaseItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseItem::class);
    }
}
