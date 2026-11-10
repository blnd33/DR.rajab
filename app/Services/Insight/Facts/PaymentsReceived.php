<?php

namespace App\Services\Insight\Facts;

use App\Enums\MoneyTransactionType;
use App\Models\MoneyTransaction;
use App\Services\Insight\Fact;
use App\Services\Insight\FactContext;
use App\Services\Insight\FactResult;
use App\Services\Insight\FactSection;
use App\Services\Reports\SnapshotConverters;
use Brick\Math\BigDecimal;

/**
 * Money that came in from customers in the period — paid with a sale, or a later payment from
 * a hospital — from the cash-box ledger (a voided payment's reversal counts against it), each
 * amount converted with its own exchange rates.
 */
class PaymentsReceived extends Fact
{
    private const TYPES = [MoneyTransactionType::SaleReceipt, MoneyTransactionType::CustomerPayment];

    public static function key(): string
    {
        return 'payments-received';
    }

    public function title(): string
    {
        return __('Payments received');
    }

    public function section(): FactSection
    {
        return FactSection::Money;
    }

    protected function permission(): string
    {
        return 'sales.view_any';
    }

    public function usesPeriod(): bool
    {
        return true;
    }

    public function build(FactContext $context): FactResult
    {
        $types = array_map(fn (MoneyTransactionType $t) => $t->value, self::TYPES);
        $rows = $context->scope(MoneyTransaction::query(), 'sales.view_any')
            ->whereBetween('occurred_at', $context->bounds())
            ->where(fn ($q) => $q->whereIn('type', $types)
                ->orWhere(fn ($r) => $r->where('type', MoneyTransactionType::Reversal)
                    ->whereIn('reverses_id', MoneyTransaction::query()->whereIn('type', $types)->select('id'))))
            ->get(['id', 'type', 'amount', 'currency', 'fx_snapshot_id', 'reverses_id']);

        $reversed = MoneyTransaction::query()->whereIn('id', $rows->pluck('reverses_id')->filter())->toBase()->pluck('type', 'id');
        $fx = app(SnapshotConverters::class);
        $missing = false;
        $byType = [];
        $count = 0;

        foreach ($rows as $row) {
            $type = $row->type === MoneyTransactionType::Reversal ? MoneyTransactionType::tryFrom((string) ($reversed[$row->reverses_id] ?? '')) : $row->type;
            $amount = $fx->convert((string) $row->amount, $row->currency, $context->currency, $row->fx_snapshot_id);
            $missing = $missing || $amount === null;
            $key = $type instanceof MoneyTransactionType ? $type->value : '';
            $byType[$key] = ($byType[$key] ?? BigDecimal::zero())->plus($amount ?? BigDecimal::zero());
            $count += $row->type === MoneyTransactionType::Reversal ? -1 : 1;
        }

        $total = array_reduce($byType, fn (BigDecimal $sum, BigDecimal $part) => $sum->plus($part), BigDecimal::zero());
        $lines = [];

        foreach (self::TYPES as $type) {
            if (isset($byType[$type->value]) && ! $byType[$type->value]->isZero()) {
                $label = $type === MoneyTransactionType::SaleReceipt ? __('Paid with the sale') : __('Later payments from hospitals');
                $lines[] = self::line($label, self::money($byType[$type->value], $context->currency));
            }
        }

        return $this->result($context, self::money($total, $context->currency), $lines,
            ['total' => self::amount($total), 'payments' => max($count, 0)],
            'filament.admin.resources.customer-payments.index',
            note: $missing ? __('Some amounts could not be converted — set the exchange rates.') : null);
    }
}
