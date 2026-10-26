<?php

namespace App\Models;

use App\Enums\SupplierReturnReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $supplier_return_id
 * @property int $product_id
 * @property ?int $stock_unit_id
 * @property ?int $stock_batch_id
 * @property int $quantity
 * @property SupplierReturnReason $reason
 * @property string $unit_credit
 * @property ?string $currency
 */
class SupplierReturnItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'reason' => SupplierReturnReason::class,
            'unit_credit' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<SupplierReturn, $this> */
    public function supplierReturn(): BelongsTo
    {
        return $this->belongsTo(SupplierReturn::class);
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
