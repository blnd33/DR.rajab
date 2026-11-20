<?php

namespace App\Services\Reports;

/**
 * Stock → Input: what came into the company in the period (opening stock, imports, purchases,
 * returns in, count corrections that added pieces).
 */
class StockInputReport extends StockMovementsReport
{
    protected ?string $flow = 'in';

    public static function key(): string
    {
        return 'stock-input';
    }

    public function title(): string
    {
        return __('Input');
    }

    public function description(): ?string
    {
        return __('Stock that came in: opening stock, Excel imports, purchases received, returns from customers and surgeries, and count corrections that added pieces.');
    }
}
