<?php

namespace App\Services\Reports;

/**
 * Stock → Stock value per item / group: what is on hand now at true cost, per item, group or
 * group and subgroup.
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
        return __('Value at true cost of what is on hand now, per item, group or subgroup.');
    }

    public function groups(): array
    {
        return [
            'product' => __('Item'),
            'group' => __('Group'),
            'subgroup' => __('Group and subgroup'),
        ];
    }
}
