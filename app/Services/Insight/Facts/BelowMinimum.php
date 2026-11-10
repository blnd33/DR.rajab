<?php

namespace App\Services\Insight\Facts;

use App\Models\LowStock;
use App\Services\Insight\Fact;
use App\Services\Insight\FactContext;
use App\Services\Insight\FactResult;
use App\Services\Insight\FactSection;
use App\Services\Stock\LowStockDetector;

/**
 * Products below their minimum in a branch, the biggest shortage first.
 */
class BelowMinimum extends Fact
{
    public function __construct(private readonly LowStockDetector $lowStock) {}

    public static function key(): string
    {
        return 'below-minimum';
    }

    public function title(): string
    {
        return __('Below minimum');
    }

    public function section(): FactSection
    {
        return FactSection::Stock;
    }

    protected function permission(): string
    {
        return 'stock.view';
    }

    public function build(FactContext $context): FactResult
    {
        $query = $context->scope($this->lowStock->query($context->user), 'stock.view');
        $total = (clone $query)->count();
        $lines = $query->orderByDesc('shortage')->limit(self::MAX_LINES)->get()
            ->map(fn (LowStock $row) => self::line(
                ($row->product->sku ?? '—').' · '.($row->branch->code ?? ''),
                self::count($row->qty_available).' / '.self::count($row->min_stock),
            ))->all();

        return $this->result($context, self::count($total), $lines, ['products' => $total],
            'filament.admin.pages.low-stock-report',
            tone: $total > 0 ? FactResult::WARNING : FactResult::GOOD);
    }
}
