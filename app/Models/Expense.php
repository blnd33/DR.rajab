<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\ExpenseStatus;
use App\Services\Expenses\ExpenseService;
use App\Services\Money\ApprovalHandler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Money spent from a box (Module 21), optionally spread over branches.
 *
 * @property int $id
 * @property string $amount
 * @property string $amount_usd
 * @property Carbon $expense_date
 * @property ExpenseStatus $status
 * @property bool $is_allocated
 * @property ?Carbon $approved_at
 * @property ?Carbon $voided_at
 * @property string $number
 * @property ?int $branch_id
 * @property int $expense_category_id
 * @property string $currency
 * @property int $fx_snapshot_id
 * @property int $cash_box_id
 * @property ?string $payee
 * @property ?string $description
 * @property ?int $money_transaction_id
 */
class Expense extends Model implements ApprovalHandler
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'amount_usd' => 'decimal:4',
            'expense_date' => DateOnly::class,
            'status' => ExpenseStatus::class,
            'is_allocated' => 'boolean',
            'approved_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('expenses')->logOnly(['status', 'amount', 'payee', 'description', 'void_reason'])->logOnlyDirty()->dontSubmitEmptyLogs();
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

    /** @return BelongsTo<ExpenseCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /** @return BelongsTo<CashBox, $this> */
    public function cashBox(): BelongsTo
    {
        return $this->belongsTo(CashBox::class);
    }

    /** @return BelongsTo<RecurringExpense, $this> */
    public function recurring(): BelongsTo
    {
        return $this->belongsTo(RecurringExpense::class, 'recurring_expense_id');
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

    /** @return HasMany<ExpenseAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(ExpenseAllocation::class);
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
        app(ExpenseService::class)->post($this, $by);
    }

    public function approvalRejected(User $by, string $reason): void
    {
        app(ExpenseService::class)->reject($this, $by, $reason);
    }
}
