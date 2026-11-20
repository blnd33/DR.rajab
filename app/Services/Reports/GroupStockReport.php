<?php

namespace App\Services\Reports;

use Brick\Math\BigDecimal;

/**
 * Stock → Item / group / subgroup stock (and, with values, Stock value per item / group): on hand
 * now per group (top category), subgroup (the category under it) or item.
 */
class GroupStockReport extends Report
{
    /** Whether the screen also shows the value at true cost. */
    protected bool $withValue = false;

    public function __construct(private readonly OnHandStock $stock) {}

    public static function key(): string
    {
        return 'group-stock';
    }

    public function title(): string
    {
        return __('Item / group / subgroup stock');
    }

    public function description(): ?string
    {
        return __('Pieces on hand now per group, subgroup or item (groups are the top categories, subgroups the categories under them).');
    }

    public function filters(): array
    {
        return $this->withValue ? ['branch', 'currency', 'group'] : ['branch', 'group'];
    }

    public function groups(): array
    {
        return [
            'group' => __('Group'),
            'subgroup' => __('Group and subgroup'),
            'product' => __('Item'),
        ];
    }

    public function columns(ReportContext $context): array
    {
        $head = match ($context->group) {
            'subgroup' => [ReportColumn::text('group', __('Group')), ReportColumn::text('subgroup', __('Subgroup'))],
            'product' => [ReportColumn::code('sku', __('SKU')), ReportColumn::text('item', __('Item')), ReportColumn::text('group', __('Group')), ReportColumn::text('subgroup', __('Subgroup'))],
            default => [ReportColumn::text('group', __('Group'))],
        };

        return [
            ...$head,
            ReportColumn::number('quantity', __('On hand')),
            ...($this->withValue ? [ReportColumn::money('value', __('Value (true cost)'))] : []),
        ];
    }

    public function rows(ReportContext $context): array
    {
        $lines = $this->stock->lines($context);
        $items = $this->stock->items(array_column($lines, 'product_id'));
        $rows = [];

        foreach ($lines as $line) {
            $item = $items[$line['product_id']] ?? ['sku' => '—', 'name' => '—', 'group' => __('No category'), 'subgroup' => '—'];
            $key = match ($context->group) {
                'subgroup' => $item['group'].'|'.$item['subgroup'],
                'product' => 'p'.$line['product_id'],
                default => $item['group'],
            };

            $rows[$key] ??= ['key' => $key, 'sku' => $item['sku'], 'item' => $item['name'], 'group' => $item['group'], 'subgroup' => $item['subgroup'], 'quantity' => 0, 'value' => '0'];
            $rows[$key]['quantity'] += $line['quantity'];
            $rows[$key]['value'] = self::money(BigDecimal::of($rows[$key]['value'])->plus($line['value']));
        }

        return array_values($rows);
    }
}
