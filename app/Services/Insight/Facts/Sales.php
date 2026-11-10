<?php

namespace App\Services\Insight\Facts;

use App\Services\Insight\Fact;
use App\Services\Insight\FactContext;
use App\Services\Insight\FactResult;
use App\Services\Insight\FactSection;
use App\Services\Reports\SalesProfitReport;
use Brick\Math\BigDecimal;

/**
 * Sales in the period by hospital — the Sales and profit report's own numbers (after
 * discounts, voided sales left out, each sale converted with its own exchange rates).
 */
class Sales extends Fact
{
    public function __construct(private readonly SalesProfitReport $report) {}

    public static function key(): string
    {
        return 'sales';
    }

    public function title(): string
    {
        return __('Sales');
    }

    public function section(): FactSection
    {
        return FactSection::Money;
    }

    protected function permission(): string
    {
        return 'reports.view';
    }

    public function usesPeriod(): bool
    {
        return true;
    }

    public function build(FactContext $context): FactResult
    {
        $report = $context->report($this->report, 'customer');
        $rows = $this->report->rows($report);
        usort($rows, fn ($a, $b) => BigDecimal::of($b['revenue'])->compareTo($a['revenue']));

        $total = array_reduce($rows, fn (BigDecimal $sum, $row) => $sum->plus($row['revenue']), BigDecimal::zero());
        $invoices = array_sum(array_column($rows, 'invoices'));
        $lines = array_map(fn ($row) => self::line((string) $row['customer'], self::money($row['revenue'], $context->currency)), $rows);

        return $this->result($context, self::money($total, $context->currency), $lines,
            ['total' => self::amount($total), 'invoices' => $invoices],
            'filament.admin.pages.reports.sales-profit',
            note: $report->missingRates ? __('Some amounts could not be converted — set the exchange rates.') : null);
    }
}
