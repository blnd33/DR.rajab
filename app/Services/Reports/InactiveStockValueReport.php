<?php

namespace App\Services\Reports;

/**
 * Inactive Stock Value: the value at true cost of stock not used for the chosen number of days.
 */
class InactiveStockValueReport extends InactiveStockReport
{
    protected bool $withValue = true;

    public static function key(): string
    {
        return 'inactive-stock-value';
    }

    public function title(): string
    {
        return __('Inactive stock value');
    }

    public function description(): ?string
    {
        return __('Value at true cost of the stock on hand that has not been used (sold, used in surgery, written off, returned or counted out) for the chosen number of days, although it has been in stock that long.');
    }
}
