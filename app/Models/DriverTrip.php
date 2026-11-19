<?php

namespace App\Models;

use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One trip of a driver: when the goods arrived, when we received them, and what we paid
 * (IQD, USD or EUR). Recorded only — no cash box moves. Never deleted: voided with a reason.
 *
 * @property int $id
 * @property int $driver_id
 * @property Carbon $arrived_on
 * @property ?Carbon $received_on
 * @property string $amount
 * @property string $currency
 * @property ?int $fx_snapshot_id
 * @property ?string $notes
 * @property ?Carbon $voided_at
 * @property ?int $voided_by
 * @property ?string $void_reason
 */
class DriverTrip extends Model
{
    use LogsActivity;
    use TracksAuthor;

    protected $fillable = [
        'driver_id',
        'arrived_on',
        'received_on',
        'amount',
        'currency',
        'fx_snapshot_id',
        'notes',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'arrived_on' => 'date',
            'received_on' => 'date',
            'amount' => 'decimal:4',
            'voided_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('drivers')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    /**
     * @param  Builder<DriverTrip>  $query
     */
    public function scopeCounted(Builder $query): void
    {
        $query->whereNull('voided_at');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /** @return BelongsTo<Driver, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /** @return BelongsTo<FxSnapshot, $this> */
    public function fxSnapshot(): BelongsTo
    {
        return $this->belongsTo(FxSnapshot::class);
    }
}
