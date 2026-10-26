<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\PaymentMethod;
use App\Enums\SupplierPaymentStatus;
use App\Services\Money\ApprovalHandler;
use App\Services\Purchasing\SupplierPaymentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Money paid to a supplier from a box, allocated to purchases.
 *
 * @property int $id
 * @property string $amount
 * @property string $amount_usd
 * @property PaymentMethod $method
 * @property Carbon $paid_at
 * @property SupplierPaymentStatus $status
 * @property string $fx_gain_loss_usd
 * @property ?Carbon $approved_at
 * @property ?Carbon $voided_at
 * @property string $number
 * @property int $supplier_id
 * @property int $cash_box_id
 * @property string $currency
 * @property int $fx_snapshot_id
 * @property ?string $reference
 * @property ?int $money_transaction_id
 */
class SupplierPayment extends Model implements ApprovalHandler
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'amount_usd' => 'decimal:4',
            'method' => PaymentMethod::class,
            'paid_at' => DateOnly::class,
            'status' => SupplierPaymentStatus::class,
            'fx_gain_loss_usd' => 'decimal:4',
            'approved_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('supplier_payments')->logOnly(['status', 'amount', 'reference', 'void_reason'])->logOnlyDirty()->dontSubmitEmptyLogs();
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

    /** @return BelongsTo<CashBox, $this> */
    public function cashBox(): BelongsTo
    {
        return $this->belongsTo(CashBox::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<SupplierPaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierPaymentAllocation::class);
    }

    /** @return BelongsTo<MoneyTransaction, $this> */
    public function moneyTransaction(): BelongsTo
    {
        return $this->belongsTo(MoneyTransaction::class);
    }

    /** @return MorphMany<Attachment, $this> */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function approvalGranted(User $by): void
    {
        $this->forceFill(['approved_by' => $by->id, 'approved_at' => now()])->save();
        app(SupplierPaymentService::class)->post($this, $by);
    }

    public function approvalRejected(User $by, string $reason): void
    {
        app(SupplierPaymentService::class)->reject($this, $by, $reason);
    }
}
