<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\ReturnCondition;
use App\Enums\ReturnReason;
use App\Enums\StockStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $customer_return_id
 * @property int $product_id
 * @property ?int $stock_unit_id
 * @property ?int $stock_batch_id
 * @property int $quantity
 * @property ?string $lot
 * @property ?Carbon $expiry_date
 * @property ?StockStatus $previous_status
 * @property ReturnReason $reason
 * @property ReturnCondition $condition
 * @property ?string $outcome
 * @property ?int $write_off_id
 */
class CustomerReturnItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'expiry_date' => DateOnly::class,
            'previous_status' => StockStatus::class,
            'reason' => ReturnReason::class,
            'condition' => ReturnCondition::class,
        ];
    }

    /** @return BelongsTo<CustomerReturn, $this> */
    public function customerReturn(): BelongsTo
    {
        return $this->belongsTo(CustomerReturn::class);
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

    /** @return BelongsTo<WriteOff, $this> */
    public function writeOff(): BelongsTo
    {
        return $this->belongsTo(WriteOff::class);
    }

    /** @return MorphTo<Model, $this> */
    public function origin(): MorphTo
    {
        return $this->morphTo();
    }
}
