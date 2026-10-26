<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The part of a customer payment that settles one invoice.
 *
 * @property int $id
 * @property string $amount_in_payment_currency
 * @property string $amount_in_invoice_currency
 * @property string $rate_used
 * @property string $fx_gain_loss_usd
 */
class CustomerPaymentAllocation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount_in_payment_currency' => 'decimal:4',
            'amount_in_invoice_currency' => 'decimal:4',
            'rate_used' => 'decimal:8',
            'fx_gain_loss_usd' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<CustomerPayment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(CustomerPayment::class, 'customer_payment_id');
    }

    /** @return BelongsTo<SalesInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }
}
