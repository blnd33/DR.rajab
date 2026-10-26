<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\ClosingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Daily count of a box: expected vs counted; locked after submit.
 *
 * @property int $id
 * @property Carbon $business_date
 * @property string $opening_balance
 * @property string $expected_balance
 * @property ?string $counted_balance
 * @property string $difference
 * @property ?array<string, mixed> $denominations
 * @property ClosingStatus $status
 * @property ?Carbon $submitted_at
 * @property ?Carbon $reviewed_at
 * @property int $cash_box_id
 * @property ?string $difference_reason
 * @property ?string $review_note
 */
class CashBoxClosing extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'business_date' => DateOnly::class,
            'opening_balance' => 'decimal:4',
            'expected_balance' => 'decimal:4',
            'counted_balance' => 'decimal:4',
            'difference' => 'decimal:4',
            'denominations' => 'array',
            'status' => ClosingStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('cash_box_closings')->logOnly(['status', 'counted_balance', 'difference', 'difference_reason', 'review_note'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    /** @return BelongsTo<CashBox, $this> */
    public function cashBox(): BelongsTo
    {
        return $this->belongsTo(CashBox::class);
    }

    /** @return BelongsTo<User, $this> */
    public function counter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return BelongsTo<MoneyTransaction, $this> */
    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(MoneyTransaction::class, 'adjustment_transaction_id');
    }
}
