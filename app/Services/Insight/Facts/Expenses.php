<?php

namespace App\Services\Insight\Facts;

use App\Services\Insight\Fact;
use App\Services\Insight\FactContext;
use App\Services\Insight\FactResult;
use App\Services\Insight\FactSection;
use App\Services\Reports\ExpensesReport;
use Brick\Math\BigDecimal;

/**
 * Expenses in the period by category, from the Expenses report (shared expenses split over
 * the branches as booked).
 */
class Expenses extends Fact
{
    public function __construct(private readonly ExpensesReport $report) {}

    public static function key(): string
    {
        return 'expenses';
    }

    public function title(): string
    {
        return __('Expenses');
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
        $report = $context->report($this->report, 'category');
        $rows = $this->report->rows($report);
        usort($rows, fn ($a, $b) => BigDecimal::of($b['amount'])->compareTo($a['amount']));

        $total = array_reduce($rows, fn (BigDecimal $sum, $row) => $sum->plus($row['amount']), BigDecimal::zero());
        $lines = array_map(fn ($row) => self::line((string) $row['category'], self::money($row['amount'], $context->currency)), $rows);

        return $this->result($context, self::money($total, $context->currency), $lines,
            ['total' => self::amount($total), 'count' => array_sum(array_column($rows, 'count'))],
            'filament.admin.pages.reports.expenses',
            note: $report->missingRates ? __('Some amounts could not be converted — set the exchange rates.') : null);
    }
}
