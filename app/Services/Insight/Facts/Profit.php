<?php

namespace App\Services\Insight\Facts;

use App\Models\User;
use App\Services\Insight\Fact;
use App\Services\Insight\FactContext;
use App\Services\Insight\FactResult;
use App\Services\Insight\FactSection;
use App\Services\Reports\SalesProfitReport;
use App\Support\Locale\Display;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Revenue, true cost and profit for the period (needs reports.profit), from the Sales and
 * profit report.
 */
class Profit extends Fact
{
    public function __construct(private readonly SalesProfitReport $report) {}

    public static function key(): string
    {
        return 'profit';
    }

    public function title(): string
    {
        return __('Profit');
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

    public function canSee(User $user): bool
    {
        return parent::canSee($user) && $user->can('reports.profit');
    }

    public function build(FactContext $context): FactResult
    {
        $report = $context->report($this->report, 'month');
        $rows = $this->report->rows($report);
        $revenue = array_reduce($rows, fn (BigDecimal $sum, $row) => $sum->plus($row['revenue']), BigDecimal::zero());
        $cost = array_reduce($rows, fn (BigDecimal $sum, $row) => $sum->plus($row['cost']), BigDecimal::zero());
        $profit = $revenue->minus($cost);
        $currency = $context->currency;

        $lines = $revenue->isZero() ? [] : [
            self::line(__('Revenue'), self::money($revenue, $currency)),
            self::line(__('Cost'), self::money($cost, $currency)),
            self::line(__('Margin %'), Display::number((string) $profit->multipliedBy(100)->dividedBy($revenue, 1, RoundingMode::HalfUp), 1).' %'),
        ];

        return $this->result($context, self::money($profit, $currency), $lines,
            ['profit' => self::amount($profit), 'revenue' => self::amount($revenue)],
            'filament.admin.pages.reports.sales-profit',
            tone: $profit->isNegative() ? FactResult::DANGER : FactResult::NEUTRAL,
            note: $report->missingRates ? __('Some amounts could not be converted — set the exchange rates.') : null);
    }
}
