<?php

namespace App\Models;

use App\Enums\BoxTransferStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Cash moved between two boxes of the same currency.
 *
 * @property int $id
 * @property string $amount
 * @property BoxTransferStatus $status
 * @property Carbon $sent_at
 * @property ?Carbon $received_at
 * @property ?Carbon $voided_at
 * @property string $number
 * @property int $from_cash_box_id
 * @property int $to_cash_box_id
 * @property string $currency
 */
class BoxTransfer extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'status' => BoxTransferStatus::class,
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('box_transfers')->logOnly(['status', 'amount', 'void_reason'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function activityReferenceCode(): string
    {
        return (string) $this->getAttribute('number');
    }

    /** @return BelongsTo<CashBox, $this> */
    public function fromBox(): BelongsTo
    {
        return $this->belongsTo(CashBox::class, 'from_cash_box_id');
    }

    /** @return BelongsTo<CashBox, $this> */
    public function toBox(): BelongsTo
    {
        return $this->belongsTo(CashBox::class, 'to_cash_box_id');
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /** @return BelongsTo<User, $this> */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
