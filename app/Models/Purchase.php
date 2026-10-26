<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use App\Enums\ReceivingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Supplier invoice (Module 7): lines, receipts, landed costs, payments.
 *
 * @property int $id
 * @property Carbon $invoice_date
 * @property ?Carbon $due_date
 * @property string $subtotal
 * @property string $discount
 * @property string $total
 * @property string $paid_amount
 * @property PurchaseStatus $status
 * @property ReceivingStatus $receiving_status
 * @property PaymentStatus $payment_status
 * @property ?Carbon $confirmed_at
 * @property ?Carbon $voided_at
 * @property string $number
 * @property int $supplier_id
 * @property string $supplier_invoice_number
 * @property int $branch_id
 * @property string $currency
 * @property int $fx_snapshot_id
 * @property ?string $notes
 */
class Purchase extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'invoice_date' => DateOnly::class,
            'due_date' => DateOnly::class,
            'subtotal' => 'decimal:4',
            'discount' => 'decimal:4',
            'total' => 'decimal:4',
            'paid_amount' => 'decimal:4',
            'status' => PurchaseStatus::class,
            'receiving_status' => ReceivingStatus::class,
            'payment_status' => PaymentStatus::class,
            'confirmed_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('purchases')->logOnly(['status', 'supplier_invoice_number', 'invoice_date', 'due_date', 'total', 'payment_status', 'receiving_status', 'void_reason'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function activityReferenceCode(): string
    {
        return (string) $this->getAttribute('number');
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<FxSnapshot, $this> */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(FxSnapshot::class, 'fx_snapshot_id');
    }

    /** @return HasMany<PurchaseItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    /** @return HasMany<PurchaseReceipt, $this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class);
    }

    /** @return HasMany<LandedCost, $this> */
    public function landedCosts(): HasMany
    {
        return $this->hasMany(LandedCost::class);
    }

    /** @return HasMany<SupplierPaymentAllocation, $this> */
    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(SupplierPaymentAllocation::class);
    }

    /** @return MorphMany<Attachment, $this> */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
