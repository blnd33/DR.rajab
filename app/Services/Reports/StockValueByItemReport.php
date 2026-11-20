<?php

namespace App\Services\Reports;

/**
 * Stock → Stock value per item / group: what is on hand now at true cost, per item, specialty,
 * category or subcategory.
 */
class StockValueByItemReport extends GroupStockReport
{
    protected bool $withValue = true;

    public static function key(): string
    {
        return 'stock-value-items';
    }

    public function title(): string
    {
        return __('Stock value per item / group');
    }

    public function description(): ?string
    {
        return __('Value at true cost of what is on hand now, per item, specialty, category or subcategory.');
    }

    public function groups(): array
    {
        return [
            'item' => __('Item'),
            'specialty' => __('Specialty'),
            'category' => __('Specialty > Category'),
            'subcategory' => __('Specialty > Category > Subcategory'),
        ];
    }
}
