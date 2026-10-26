<?php

namespace App\Services\Money;

use App\Enums\CashBoxType;
use App\Enums\MoneyTransactionType;
use App\Exceptions\Domain\MoneyRuleViolation;
use App\Models\CashBox;
use App\Models\FxSnapshot;
use App\Models\MoneyTransaction;
use App\Models\User;
use App\Support\Locale\Display;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * THE only writer of money_transactions and cash_boxes.current_balance (P4-3): one DB
 * transaction, the box locked, a signed ledger row with its USD value through the snapshot
 * and the running balance. Callers (document services) check permissions and rules.
 */
class MoneyLedgerService
{
    public function __construct(private readonly FxService $fx) {}

    /**
     * Post a signed amount (+ in / − out, in the box currency) to a box.
     */
    public function post(
        CashBox $box,
        MoneyTransactionType $type,
        string $amount,
        User $by,
        ?Model $reference = null,
        ?string $group = null,
        ?string $description = null,
        ?FxSnapshot $snapshot = null,
        ?string $appliedRate = null,
        ?int $reverses = null,
    ): MoneyTransaction {
        return DB::transaction(function () use ($box, $type, $amount, $by, $reference, $group, $description, $snapshot, $appliedRate, $reverses) {
            /** @var CashBox $locked */
            $locked = CashBox::query()->lockForUpdate()->findOrFail($box->id);
            $value = BigDecimal::of($amount)->toScale(4, RoundingMode::HalfUp);

            if ($value->isZero()) {
                throw new MoneyRuleViolation(__('The amount must not be zero.'), 'amount');
            }

            if (! $locked->is_active) {
                throw new MoneyRuleViolation(__('Cash box :box is closed.', ['box' => $locked->name]));
            }

            $balance = BigDecimal::of($locked->current_balance)->plus($value);

            if ($balance->isNegative() && ($locked->type === CashBoxType::Cash || ! $locked->allow_overdraft)) {
                throw new MoneyRuleViolation(__('Not enough money in :box: :balance there.', [
                    'box' => $locked->name,
                    'balance' => Display::money($locked->current_balance, $locked->currency),
                ]), 'amount');
            }

            $snapshot ??= $this->fx->currentOrFail();

            $transaction = new MoneyTransaction([
                'movement_group' => $group ?? (string) Str::uuid(),
                'cash_box_id' => $locked->id,
                'branch_id' => $locked->branch_id,
                'type' => $type,
                'amount' => (string) $value,
                'currency' => $locked->currency,
                'fx_snapshot_id' => $snapshot->id,
                'applied_rate' => $appliedRate,
                'amount_usd' => $this->fx->converter($snapshot)->toUsd((string) $value, $locked->currency),
                'balance_after' => (string) $balance,
                'reverses_id' => $reverses,
                'description' => $description,
                'user_id' => $by->id,
                'occurred_at' => now(),
            ]);

            if ($reference !== null) {
                $transaction->reference()->associate($reference);
            }

            $transaction->save();

            $locked->forceFill(['current_balance' => (string) $balance])->save();
            $box->setRawAttributes($locked->getAttributes(), true);

            return $transaction;
        });
    }

    /**
     * Undo a ledger row with a new opposite row (same snapshot, so the USD value nets to zero).
     */
    public function reverse(MoneyTransaction $transaction, User $by, string $reason, ?string $group = null): MoneyTransaction
    {
        return DB::transaction(function () use ($transaction, $by, $reason, $group) {
            /** @var MoneyTransaction $original */
            $original = MoneyTransaction::query()->lockForUpdate()->findOrFail($transaction->id);

            if (MoneyTransaction::query()->where('reverses_id', $original->id)->exists()) {
                throw new MoneyRuleViolation(__('This money movement was already reversed.'));
            }

            return $this->post(
                $original->cashBox,
                MoneyTransactionType::Reversal,
                (string) BigDecimal::of($original->amount)->negated(),
                $by,
                $original->reference,
                $group,
                $reason,
                FxSnapshot::query()->findOrFail($original->fx_snapshot_id),
                $original->applied_rate,
                $original->id,
            );
        });
    }
}
