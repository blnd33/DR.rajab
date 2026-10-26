<?php

namespace App\Models;

use App\Enums\MoneyTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * THE money ledger (append-only). Written only by MoneyLedgerService.
 *
 * @property int $id
 * @property MoneyTransactionType $type
 * @property string $amount
 * @property string $amount_usd
 * @property string $balance_after
 * @property ?string $applied_rate
 * @property Carbon $occurred_at
 * @property int $cash_box_id
 * @property ?int $branch_id
 * @property string $currency
 * @property int $fx_snapshot_id
 * @property ?int $reverses_id
 * @property ?string $description
 * @property int $user_id
 */
class MoneyTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => MoneyTransactionType::class,
            'amount' => 'decimal:4',
            'amount_usd' => 'decimal:4',
            'balance_after' => 'decimal:4',
            'applied_rate' => 'decimal:8',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('MoneyTransaction rows are append-only.'));
        static::deleting(fn () => throw new LogicException('MoneyTransaction rows are append-only.'));
    }

    /** @return BelongsTo<CashBox, $this> */
    public function cashBox(): BelongsTo
    {
        return $this->belongsTo(CashBox::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<MoneyTransaction, $this> */
    public function reversed(): BelongsTo
    {
        return $this->belongsTo(MoneyTransaction::class, 'reverses_id');
    }

    /** @return MorphTo<Model, $this> */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
