<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read-only row of the v_low_stock view: product × open branch below its minimum.
 *
 * @property string $id
 * @property int $product_id
 * @property int $branch_id
 * @property int $qty_available
 * @property int $qty_total
 * @property int $min_stock
 * @property ?int $reorder_qty
 * @property int $shortage
 */
class LowStock extends Model
{
    protected $table = 'v_low_stock';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'branch_id' => 'integer',
            'qty_available' => 'integer',
            'qty_total' => 'integer',
            'min_stock' => 'integer',
            'reorder_qty' => 'integer',
            'shortage' => 'integer',
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
