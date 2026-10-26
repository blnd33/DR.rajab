<?php

namespace App\Models;

use App\Enums\CreditNoteDirection;
use App\Enums\CreditNoteStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Credit from a supplier (returns) or to a customer.
 *
 * @property int $id
 * @property CreditNoteDirection $direction
 * @property CreditNoteStatus $status
 * @property string $amount
 * @property ?Carbon $voided_at
 * @property string $number
 * @property string $currency
 * @property ?string $reference
 */
class CreditNote extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'direction' => CreditNoteDirection::class,
            'status' => CreditNoteStatus::class,
            'amount' => 'decimal:4',
            'voided_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('credit_notes')->logOnly(['status', 'amount', 'void_reason'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function activityReferenceCode(): string
    {
        return (string) $this->getAttribute('number');
    }

    /** @return MorphTo<Model, $this> */
    public function party(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model, $this> */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
