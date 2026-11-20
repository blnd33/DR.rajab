<?php

namespace App\Services\Reports;

use App\Models\Product;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Stock → Pricelist: every active item with its cost price, selling price and profit, by SKU.
 * Shows cost, so it needs the profit permission like the other profit screens.
 */
class PriceListReport extends Report
{
    public function __construct(private readonly OnHandStock $stock) {}

    public static function key(): string
    {
        return 'price-list';
    }

    public function title(): string
    {
        return __('Pricelist');
    }

    public function description(): ?string
    {
        return __('Every active item with its cost price, selling price and profit. Items without a selling price are listed too.');
    }

    public function filters(): array
    {
        return ['group'];
    }

    public function groups(): array
    {
        return [
            'priced' => __('Items with a selling price'),
            'all' => __('All active items'),
        ];
    }

    public function needsProfit(): bool
    {
        return true;
    }

    public function columns(ReportContext $context): array
    {
        return [
            ReportColumn::code('sku', __('SKU')),
            ReportColumn::text('item', __('Item')),
            ReportColumn::text('group', __('Group')),
            ReportColumn::moneyIn('cost', __('Cost price'), 'cost_currency'),
            ReportColumn::moneyIn('selling', __('Selling price'), 'selling_currency'),
            ReportColumn::percent('margin', __('Profit %'), profit: true),
        ];
    }

    public function rows(ReportContext $context): array
    {
        $products = Product::query()
            ->where('is_active', true)
            ->when($context->group !== 'all', fn ($q) => $q->whereNotNull('selling_price'))
            ->orderBy('sku')
            ->get(['id', 'sku', 'name', 'category_id', 'purchase_price', 'purchase_currency', 'selling_price', 'selling_currency']);
        $items = $this->stock->items($products->pluck('id'));

        return $products->map(function (Product $p) use ($items) {
            $cost = $p->purchase_price !== null ? BigDecimal::of((string) $p->purchase_price) : null;
            $selling = $p->selling_price !== null ? BigDecimal::of((string) $p->selling_price) : null;
            // Profit on cost, only when both prices are in the same currency.
            $margin = $cost !== null && $selling !== null && $cost->isPositive() && $p->purchase_currency === $p->selling_currency
                ? (string) $selling->minus($cost)->multipliedBy(100)->dividedBy($cost, 1, RoundingMode::HalfUp)
                : null;

            return [
                'key' => 'p'.$p->id,
                'sku' => (string) $p->sku,
                'item' => (string) $p->name,
                'group' => $items[$p->id]['group'] ?? __('No category'),
                'cost' => $cost !== null ? self::money($cost) : null,
                'cost_currency' => $p->purchase_currency,
                'selling' => $selling !== null ? self::money($selling) : null,
                'selling_currency' => $p->selling_currency,
                'margin' => $margin,
            ];
        })->values()->all();
    }
}
