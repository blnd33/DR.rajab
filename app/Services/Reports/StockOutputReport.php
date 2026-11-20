<?php

namespace App\Services\Reports;

/**
 * Stock → Output: what left the company in the period (sales, surgery use, write-offs, returns to
 * suppliers, count corrections that removed pieces).
 */
class StockOutputReport extends StockMovementsReport
{
    protected ?string $flow = 'out';

    public static function key(): string
    {
        return 'stock-output';
    }

    public function title(): string
    {
        return __('Output');
    }

    public function description(): ?string
    {
        return __('Stock that went out: sales, use in surgery, write-offs, returns to suppliers, and count corrections that removed pieces.');
    }
}
