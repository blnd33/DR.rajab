<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One queue of things waiting for an approver (supplier payments, expenses, commissions).
 *
 * @property int $id
 * @property ApprovalType $type
 * @property ApprovalStatus $status
 * @property ?string $amount
 * @property ?string $amount_usd
 * @property Carbon $requested_at
 * @property ?Carbon $decided_at
 * @property ?int $branch_id
 * @property ?string $currency
 * @property int $requested_by
 * @property ?string $decision_note
 */
class Approval extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => ApprovalType::class,
            'status' => ApprovalStatus::class,
            'amount' => 'decimal:4',
            'amount_usd' => 'decimal:4',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('approvals')->logOnly(['status', 'decision_note'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    /** @return MorphTo<Model, $this> */
    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
