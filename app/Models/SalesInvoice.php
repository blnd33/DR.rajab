<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\PaymentTerms;
use App\Enums\SalesInvoiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A sale (Module 13): units / lots sold, paid into a box.
 *
 * @property int $id
 * @property string $subtotal
 * @property string $discount
 * @property string $total
 * @property string $paid_amount
 * @property PaymentTerms $payment_terms
 * @property ?Carbon $due_date
 * @property SalesInvoiceStatus $status
 * @property ?Carbon $issued_at
 * @property ?Carbon $voided_at
 * @property string $number
 * @property int $branch_id
 * @property ?int $customer_id
 * @property ?string $walk_in_name
 * @property string $currency
 * @property int $fx_snapshot_id
 */
class SalesInvoice extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:4',
            'discount' => 'decimal:4',
            'total' => 'decimal:4',
            'paid_amount' => 'decimal:4',
            'payment_terms' => PaymentTerms::class,
            'due_date' => DateOnly::class,
            'status' => SalesInvoiceStatus::class,
            'issued_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('sales_invoices')->logOnly(['status', 'total', 'paid_amount', 'void_reason'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function activityReferenceCode(): string
    {
        return (string) $this->getAttribute('number');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<Surgery, $this> */
    public function surgery(): BelongsTo
    {
        return $this->belongsTo(Surgery::class);
    }

    /** @return HasMany<SalesInvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SalesInvoiceItem::class);
    }

    /** @return HasMany<CustomerPaymentAllocation, $this> */
    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(CustomerPaymentAllocation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<FxSnapshot, $this> */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(FxSnapshot::class, 'fx_snapshot_id');
    }
}
