<?php

namespace App\Services\Money;

use App\Enums\MoneyTransactionType;
use App\Exceptions\Domain\MoneyRuleViolation;
use App\Models\CashBox;
use App\Models\CurrencyExchange;
use App\Models\MoneyTransaction;
use App\Models\User;
use App\Services\Numbering\DocumentNumberGenerator;
use App\Services\Permissions\BranchScopeResolver;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Own money from one box to a box in another currency at a deal rate (Module 20, P4-6).
 * Gain / loss = value received − value given, both in USD at the reference snapshot.
 */
class CurrencyExchangeService
{
    public function __construct(
        private readonly MoneyLedgerService $ledger,
        private readonly FxService $fx,
        private readonly BranchScopeResolver $scope,
        private readonly DocumentNumberGenerator $numbers,
    ) {}

    /**
     * @param  string  $rate  deal rate: 1 unit of the from-currency = $rate of the to-currency
     */
    public function exchange(CashBox $from, CashBox $to, string $fromAmount, string $rate, User $by, ?string $note = null): CurrencyExchange
    {
        $this->authorize($by, $from->branch_id);

        if ($from->currency === $to->currency) {
            throw new MoneyRuleViolation(__('Both boxes have the same currency — use a box transfer.'), 'to_cash_box_id');
        }

        $amount = BigDecimal::of(str_replace(',', '', trim($fromAmount)) ?: '0')->toScale(4, RoundingMode::HalfUp);
        $dealRate = BigDecimal::of(str_replace(',', '', trim($rate)) ?: '0')->toScale(8, RoundingMode::HalfUp);

        if (! $amount->isPositive() || ! $dealRate->isPositive()) {
            throw new MoneyRuleViolation(__('Amount and rate must be above zero.'), 'amount');
        }

        $toAmount = $amount->multipliedBy($dealRate)->toScale(4, RoundingMode::HalfUp);

        return DB::transaction(function () use ($from, $to, $amount, $dealRate, $toAmount, $by, $note) {
            $snapshot = $this->fx->currentOrFail();
            $converter = $this->fx->converter($snapshot);
            $gainLoss = BigDecimal::of($converter->toUsd((string) $toAmount, $to->currency))->minus($converter->toUsd((string) $amount, $from->currency));

            $exchange = CurrencyExchange::query()->create([
                'number' => $this->numbers->next('CEX', $from->branch),
                'branch_id' => $from->branch_id,
                'from_cash_box_id' => $from->id,
                'to_cash_box_id' => $to->id,
                'from_amount' => (string) $amount,
                'from_currency' => $from->currency,
                'to_amount' => (string) $toAmount,
                'to_currency' => $to->currency,
                'rate' => (string) $dealRate,
                'fx_snapshot_id' => $snapshot->id,
                'gain_loss_usd' => (string) $gainLoss,
                'performed_by' => $by->id,
                'performed_at' => now(),
                'note' => $note,
            ]);

            $group = (string) Str::uuid();
            $this->ledger->post($from, MoneyTransactionType::ExchangeOut, (string) $amount->negated(), $by, $exchange, $group, $note, $snapshot, (string) $dealRate);
            $this->ledger->post($to, MoneyTransactionType::ExchangeIn, (string) $toAmount, $by, $exchange, $group, $note, $snapshot, (string) $dealRate);

            return $exchange;
        });
    }

    public function void(CurrencyExchange $exchange, string $reason, User $by): CurrencyExchange
    {
        $this->authorize($by, $exchange->branch_id);

        if (blank($reason)) {
            throw new MoneyRuleViolation(__('A reason is required.'), 'reason');
        }

        if ($exchange->voided_at !== null) {
            throw new MoneyRuleViolation(__('This exchange was already voided.'));
        }

        return DB::transaction(function () use ($exchange, $reason, $by) {
            $group = (string) Str::uuid();

            MoneyTransaction::query()->where('reference_type', $exchange->getMorphClass())->where('reference_id', $exchange->id)
                ->whereIn('type', [MoneyTransactionType::ExchangeIn, MoneyTransactionType::ExchangeOut])
                ->orderByDesc('amount') // give back the money received first
                ->get()
                ->each(fn (MoneyTransaction $leg) => $this->ledger->reverse($leg, $by, $reason, $group));

            $exchange->forceFill(['voided_by' => $by->id, 'voided_at' => now(), 'void_reason' => $reason])->save();

            return $exchange;
        });
    }

    private function authorize(User $by, ?int $branchId): void
    {
        if (! $this->scope->allows($by, 'currency_exchange.create', $branchId)) {
            throw new AuthorizationException(__('You are not allowed to exchange money for this box.'));
        }
    }
}
