<?php

namespace App\Services\Reports;

use Brick\Math\BigDecimal;

/**
 * Stock → Stock Volume per Specialty > Category > Subcategory / Items Group > Item (and, with
 * values, Stock Value per Specialty > Category > Subcategory / Items Group > Item): on hand now per specialty, category (top of the category tree),
 * subcategory / items group (the level under it) or item.
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
        return __('Stock Volume per Specialty > Category > Subcategory / Items Group > Item');
    }

    public function description(): ?string
    {
        return __('Pieces on hand now per specialty, category, subcategory (items group) or item.');
    }

    public function filters(): array
    {
        return $this->withValue ? ['branch', 'currency', 'group'] : ['branch', 'group'];
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

    public function columns(ReportContext $context): array
    {
        $levels = [
            ReportColumn::text('specialty', __('Specialty')),
            ReportColumn::text('category', __('Category')),
            ReportColumn::text('subcategory', __('Subcategory / items group')),
        ];

        $head = match ($context->group) {
            'category' => array_slice($levels, 0, 2),
            'subcategory' => $levels,
            'item' => [ReportColumn::code('sku', __('SKU')), ReportColumn::text('item', __('Item')), ...$levels],
            default => array_slice($levels, 0, 1),
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
            $item = $items[$line['product_id']] ?? ['sku' => '—', 'name' => '—', 'specialty' => __('No specialty'), 'category' => __('No category'), 'subcategory' => '—'];
            $key = match ($context->group) {
                'category' => $item['specialty'].'|'.$item['category'],
                'subcategory' => $item['specialty'].'|'.$item['category'].'|'.$item['subcategory'],
                'item' => 'p'.$line['product_id'],
                default => $item['specialty'],
            };

            $rows[$key] ??= ['key' => $key, 'sku' => $item['sku'], 'item' => $item['name'], 'specialty' => $item['specialty'], 'category' => $item['category'], 'subcategory' => $item['subcategory'], 'quantity' => 0, 'value' => '0'];
            $rows[$key]['quantity'] += $line['quantity'];
            $rows[$key]['value'] = self::money(BigDecimal::of($rows[$key]['value'])->plus($line['value']));
        }

        return array_values($rows);
    }
}
