<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\InvoiceKind;
use App\Enums\PaymentTerms;
use App\Enums\SalesInvoiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * An invoice: a sale (Module 13, units / lots sold) or a surgery's fee. Paid into a box at once,
 * or left unpaid until someone marks it paid.
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
 * @property string $pricing per_item | split
 * @property int $fx_snapshot_id
 * @property InvoiceKind $kind sale | surgery
 * @property ?int $surgery_id
 * @property ?Carbon $paid_at
 * @property ?int $paid_by
 * @property ?int $paid_cash_box_id
 * @property ?int $money_transaction_id
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
            'paid_at' => 'datetime',
            'kind' => InvoiceKind::class,
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

    /** @return BelongsTo<User, $this> */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    /** @return BelongsTo<CashBox, $this> */
    public function paidBox(): BelongsTo
    {
        return $this->belongsTo(CashBox::class, 'paid_cash_box_id');
    }

    /** @return BelongsTo<MoneyTransaction, $this> */
    public function moneyTransaction(): BelongsTo
    {
        return $this->belongsTo(MoneyTransaction::class);
    }

    public function isPaid(): bool
    {
        return $this->status === SalesInvoiceStatus::Paid;
    }

    /**
     * Unpaid invoices settled by hand ("Mark as paid"); credit invoices are settled by customer payments.
     */
    public function isOpenForMarking(): bool
    {
        return $this->status === SalesInvoiceStatus::Issued && $this->payment_terms !== PaymentTerms::Credit;
    }

    /** @return BelongsTo<FxSnapshot, $this> */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(FxSnapshot::class, 'fx_snapshot_id');
    }
}
