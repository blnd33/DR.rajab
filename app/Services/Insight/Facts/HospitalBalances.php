<?php

namespace App\Services\Insight\Facts;

use App\Enums\SalesInvoiceStatus;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Services\Insight\Fact;
use App\Services\Insight\FactContext;
use App\Services\Insight\FactResult;
use App\Services\Insight\FactSection;
use App\Services\Reports\SnapshotConverters;
use Brick\Math\BigDecimal;

/**
 * What hospitals and customers still owe now: the unpaid part of every issued sale, converted
 * with each sale's own exchange rates; the five biggest balances.
 */
class HospitalBalances extends Fact
{
    public static function key(): string
    {
        return 'hospital-balances';
    }

    public function title(): string
    {
        return __('Unpaid hospital balances');
    }

    public function section(): FactSection
    {
        return FactSection::Money;
    }

    protected function permission(): string
    {
        return 'sales.view_any';
    }

    public function build(FactContext $context): FactResult
    {
        $invoices = $context->scope(SalesInvoice::query(), 'sales.view_any')
            ->whereIn('status', [SalesInvoiceStatus::Issued, SalesInvoiceStatus::PartiallyPaid])
            ->get(['id', 'customer_id', 'total', 'paid_amount', 'currency', 'fx_snapshot_id', 'due_date']);

        $fx = app(SnapshotConverters::class);
        $missing = false;
        $total = BigDecimal::zero();
        $overdue = 0;
        $byCustomer = [];
        $today = now(config('orthotrack.timezone'))->toDateString();

        foreach ($invoices as $invoice) {
            $open = BigDecimal::of((string) $invoice->total)->minus((string) ($invoice->paid_amount ?? '0'));

            if (! $open->isPositive()) {
                continue;
            }

            $amount = $fx->convert((string) $open, $invoice->currency, $context->currency, $invoice->fx_snapshot_id);
            $missing = $missing || $amount === null;
            $amount ??= BigDecimal::zero();
            $total = $total->plus($amount);
            $byCustomer[(int) $invoice->customer_id] = ($byCustomer[(int) $invoice->customer_id] ?? BigDecimal::zero())->plus($amount);
            $overdue += $invoice->due_date !== null && $invoice->due_date->toDateString() < $today ? 1 : 0;
        }

        uasort($byCustomer, fn (BigDecimal $a, BigDecimal $b) => $b->compareTo($a));
        $names = Customer::query()->whereIn('id', array_keys($byCustomer))->get()->mapWithKeys(fn (Customer $c) => [$c->id => $c->name]);
        $lines = [];

        foreach ($byCustomer as $id => $amount) {
            $lines[] = self::line($names[$id] ?? __('Walk-in'), self::money($amount, $context->currency));
        }

        return $this->result($context, self::money($total, $context->currency), $lines,
            ['total' => self::amount($total), 'customers' => count($byCustomer), 'overdue_invoices' => $overdue],
            'filament.admin.resources.sales-invoices.index',
            tone: $overdue > 0 ? FactResult::WARNING : FactResult::NEUTRAL,
            note: $missing ? __('Some amounts could not be converted — set the exchange rates.') : ($overdue > 0 ? trans_choice(':count invoice is past its due date.|:count invoices are past their due date.', $overdue, ['count' => self::count($overdue)]) : null));
    }
}
