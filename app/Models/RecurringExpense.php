<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Rent, salaries… generated every month on a set day.
 *
 * @property int $id
 * @property string $amount
 * @property int $day_of_month
 * @property Carbon $starts_on
 * @property ?Carbon $ends_on
 * @property ?Carbon $last_generated_for
 * @property bool $auto_post
 * @property bool $is_active
 * @property ?int $branch_id
 * @property int $expense_category_id
 * @property ?int $cash_box_id
 * @property string $currency
 * @property ?string $payee
 * @property ?string $description
 * @property ?int $created_by
 */
class RecurringExpense extends Model
{
    use LogsActivity;
    use TracksAuthor;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'day_of_month' => 'integer',
            'starts_on' => DateOnly::class,
            'ends_on' => DateOnly::class,
            'last_generated_for' => DateOnly::class,
            'auto_post' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('recurring_expenses')->logOnly(['amount', 'currency', 'day_of_month', 'auto_post', 'is_active'])->logOnlyDirty()->dontSubmitEmptyLogs();
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

    /** @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
