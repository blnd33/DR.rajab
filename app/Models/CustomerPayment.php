<?php

namespace App\Models;

use App\Enums\CustomerPaymentStatus;
use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Money received from a customer (credit sales).
 *
 * @property int $id
 * @property string $amount
 * @property ?string $applied_rate
 * @property PaymentMethod $method
 * @property ?Carbon $received_at
 * @property CustomerPaymentStatus $status
 * @property ?Carbon $voided_at
 * @property string $number
 * @property ?int $customer_id
 * @property int $cash_box_id
 * @property string $currency
 * @property int $fx_snapshot_id
 */
class CustomerPayment extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'applied_rate' => 'decimal:8',
            'method' => PaymentMethod::class,
            'received_at' => 'datetime',
            'status' => CustomerPaymentStatus::class,
            'voided_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('customer_payments')->logOnly(['status', 'amount', 'void_reason'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function activityReferenceCode(): string
    {
        return (string) $this->getAttribute('number');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<CashBox, $this> */
    public function cashBox(): BelongsTo
    {
        return $this->belongsTo(CashBox::class);
    }

    /** @return BelongsTo<User, $this> */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** @return HasMany<CustomerPaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(CustomerPaymentAllocation::class);
    }

    /** @return BelongsTo<MoneyTransaction, $this> */
    public function moneyTransaction(): BelongsTo
    {
        return $this->belongsTo(MoneyTransaction::class);
    }
}
