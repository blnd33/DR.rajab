<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The part of a supplier payment that settles one purchase.
 *
 * @property int $id
 * @property string $amount_in_payment_currency
 * @property string $amount_in_purchase_currency
 * @property string $rate_used
 * @property string $fx_gain_loss_usd
 */
class SupplierPaymentAllocation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount_in_payment_currency' => 'decimal:4',
            'amount_in_purchase_currency' => 'decimal:4',
            'rate_used' => 'decimal:8',
            'fx_gain_loss_usd' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<SupplierPayment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class, 'supplier_payment_id');
    }

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }
}
