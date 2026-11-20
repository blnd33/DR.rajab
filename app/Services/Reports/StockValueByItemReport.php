<?php

namespace App\Services\Reports;

/**
 * Stock → Stock Value per Specialty > Category > Subcategory / Items Group > Item: what is on
 * hand now at true cost, per specialty, category, subcategory or item.
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
        return __('Stock Value per Specialty > Category > Subcategory / Items Group > Item');
    }

    public function description(): ?string
    {
        return __('Value at true cost of what is on hand now, per specialty, category, subcategory (items group) or item.');
    }

    public function groups(): array
    {
        return [
            'specialty' => __('Specialty'),
            'category' => __('Specialty > Category'),
            'subcategory' => __('Specialty > Category > Subcategory'),
            'item' => __('Item'),
        ];
    }
}
