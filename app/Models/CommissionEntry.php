<?php

namespace App\Models;

use App\Enums\CommissionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A commission earned by a doctor for a surgery.
 *
 * @property int $id
 * @property string $amount
 * @property CommissionStatus $status
 * @property ?Carbon $approved_at
 * @property int $doctor_id
 * @property string $currency
 */
class CommissionEntry extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'status' => CommissionStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    /** @return BelongsTo<Surgery, $this> */
    public function surgery(): BelongsTo
    {
        return $this->belongsTo(Surgery::class);
    }

    /** @return BelongsTo<SurgeryItem, $this> */
    public function surgeryItem(): BelongsTo
    {
        return $this->belongsTo(SurgeryItem::class);
    }

    /** @return BelongsTo<CommissionRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(CommissionRule::class, 'commission_rule_id');
    }

    /** @return BelongsTo<MoneyTransaction, $this> */
    public function moneyTransaction(): BelongsTo
    {
        return $this->belongsTo(MoneyTransaction::class);
    }
}
