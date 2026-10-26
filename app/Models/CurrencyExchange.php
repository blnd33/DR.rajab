<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Own money converted from one box to another at a deal rate.
 *
 * @property int $id
 * @property string $from_amount
 * @property string $to_amount
 * @property string $rate
 * @property string $gain_loss_usd
 * @property Carbon $performed_at
 * @property ?Carbon $voided_at
 * @property string $number
 * @property ?int $branch_id
 * @property string $from_currency
 * @property string $to_currency
 */
class CurrencyExchange extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'from_amount' => 'decimal:4',
            'to_amount' => 'decimal:4',
            'rate' => 'decimal:8',
            'gain_loss_usd' => 'decimal:4',
            'performed_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('currency_exchanges')->logOnly(['from_amount', 'to_amount', 'rate', 'void_reason'])->logOnlyDirty()->dontSubmitEmptyLogs();
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
    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
