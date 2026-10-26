<?php

namespace App\Models;

use App\Enums\RequestPriority;
use App\Enums\StockRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A branch asking for stock (Module 9). Approval creates draft transfers.
 *
 * @property int $id
 * @property string $number
 * @property int $requesting_branch_id
 * @property ?int $preferred_source_branch_id
 * @property StockRequestStatus $status
 * @property RequestPriority $priority
 * @property ?Carbon $needed_by
 * @property ?string $notes
 * @property int $requested_by
 * @property ?string $review_note
 */
class StockRequest extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => StockRequestStatus::class,
            'priority' => RequestPriority::class,
            'needed_by' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('stock_requests')->logOnly(['status', 'review_note'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function activityReferenceCode(): string
    {
        return $this->number;
    }

    /** @return BelongsTo<Branch, $this> */
    public function requestingBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'requesting_branch_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function preferredSourceBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'preferred_source_branch_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return HasMany<StockRequestItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StockRequestItem::class);
    }

    /** @return HasMany<Transfer, $this> */
    public function transfers(): HasMany
    {
        return $this->hasMany(Transfer::class);
    }
}
