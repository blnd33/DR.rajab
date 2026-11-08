<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sold unit or lot quantity, with its cost for profit.
 *
 * @property int $id
 * @property int $quantity
 * @property string $unit_price
 * @property string $discount
 * @property string $line_total
 * @property ?string $unit_cost
 * @property int $returned_qty
 * @property int $product_id
 * @property ?int $stock_unit_id
 * @property ?int $stock_batch_id
 * @property ?string $cost_currency
 * @property ?string $cost_share percent of the invoice cost
 * @property ?string $price_share percent of the invoice amount
 */
class SalesInvoiceItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:4',
            'discount' => 'decimal:4',
            'line_total' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'returned_qty' => 'integer',
            'cost_share' => 'decimal:4',
            'price_share' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<SalesInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
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
}
