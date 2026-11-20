<?php

namespace App\Services\Reports;

use App\Models\Branch;

/**
 * Stock → Inventory / stock volume: how many pieces of each item are on hand now, per station.
 */
class InventoryReport extends Report
{
    public function __construct(private readonly OnHandStock $stock) {}

    public static function key(): string
    {
        return 'inventory';
    }

    public function title(): string
    {
        return __('Inventory / stock volume');
    }

    public function description(): ?string
    {
        return __('Pieces on hand now (serial units + lot quantities), per item and station.');
    }

    public function filters(): array
    {
        return ['branch', 'group'];
    }

    public function groups(): array
    {
        return [
            'product_branch' => __('Item and station'),
            'product' => __('Item'),
            'branch' => __('Station'),
        ];
    }

    public function columns(ReportContext $context): array
    {
        $head = match ($context->group) {
            'branch' => [ReportColumn::text('branch', __('Station'))],
            'product' => [ReportColumn::code('sku', __('SKU')), ReportColumn::text('item', __('Item'))],
            default => [ReportColumn::code('sku', __('SKU')), ReportColumn::text('item', __('Item')), ReportColumn::text('branch', __('Station'))],
        };

        return [...$head, ReportColumn::number('quantity', __('On hand'))];
    }

    public function rows(ReportContext $context): array
    {
        $lines = $this->stock->lines($context);
        $items = $this->stock->items(array_column($lines, 'product_id'));
        $branches = self::names(Branch::class, collect($lines)->pluck('branch_id'));
        $rows = [];

        foreach ($lines as $line) {
            $key = match ($context->group) {
                'branch' => 'b'.$line['branch_id'],
                'product' => 'p'.$line['product_id'],
                default => 'p'.$line['product_id'].'-b'.$line['branch_id'],
            };
            $item = $items[$line['product_id']] ?? ['sku' => '—', 'name' => '—'];

            $rows[$key] ??= ['key' => $key, 'sku' => $item['sku'], 'item' => $item['name'], 'branch' => $branches[$line['branch_id']] ?? '—', 'quantity' => 0];
            $rows[$key]['quantity'] += $line['quantity'];
        }

        return array_values($rows);
    }
}
