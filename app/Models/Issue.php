<?php

namespace App\Models;

use App\Enums\IssueSeverity;
use App\Enums\IssueStatus;
use App\Enums\IssueType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Something the Super Admin must look at (transfer / count differences, expired overrides…).
 *
 * @property int $id
 * @property IssueType $type
 * @property IssueSeverity $severity
 * @property ?int $branch_id
 * @property string $title
 * @property ?array<string, mixed> $details
 * @property IssueStatus $status
 * @property ?Carbon $resolved_at
 * @property ?string $resolution_note
 */
class Issue extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => IssueType::class,
            'severity' => IssueSeverity::class,
            'status' => IssueStatus::class,
            'details' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('issues')->logOnly(['status', 'resolution_note'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** @param Builder<Issue> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', IssueStatus::Open);
    }
}
