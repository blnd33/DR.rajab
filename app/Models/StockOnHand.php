<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read-only row of the v_stock_on_hand view (product × branch quantities).
 *
 * @property string $id
 * @property int $product_id
 * @property int $branch_id
 * @property int $qty_shelf
 * @property int $qty_in_kits
 * @property int $qty_at_hospital
 * @property int $qty_in_transit
 * @property int $qty_available
 * @property int $qty_total
 */
class StockOnHand extends Model
{
    protected $table = 'v_stock_on_hand';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'branch_id' => 'integer',
            'qty_shelf' => 'integer',
            'qty_in_kits' => 'integer',
            'qty_at_hospital' => 'integer',
            'qty_in_transit' => 'integer',
            'qty_available' => 'integer',
            'qty_total' => 'integer',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
