<?php

namespace App\Models;

use App\Enums\WriteOffReason;
use App\Enums\WriteOffStatus;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Damaged / lost / expired stock (Module 15). Stock moves only when approved.
 *
 * @property int $id
 * @property string $number
 * @property int $branch_id
 * @property WriteOffReason $reason
 * @property WriteOffStatus $status
 * @property ?string $note
 * @property int $reported_by
 * @property Carbon $reported_at
 * @property ?int $approved_by
 * @property ?Carbon $approved_at
 * @property ?string $rejection_reason
 */
class WriteOff extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'reason' => WriteOffReason::class,
            'status' => WriteOffStatus::class,
            'reported_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'total_cost_usd' => 'decimal:4',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('write_offs')->logOnly(['status', 'reason', 'note', 'rejection_reason'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function activityReferenceCode(): string
    {
        return $this->number;
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<WriteOffItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(WriteOffItem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return MorphTo<Model, $this> */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphMany<Attachment, $this> */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * Cost per currency (true unit cost × quantity).
     *
     * @return array<string, string>
     */
    public function costByCurrency(): array
    {
        $totals = [];

        foreach ($this->items as $item) {
            $totals[$item->cost_currency] = (string) BigDecimal::of($totals[$item->cost_currency] ?? '0')
                ->plus(BigDecimal::of($item->unit_cost)->multipliedBy($item->quantity))
                ->toScale(4);
        }

        return $totals;
    }
}
