<?php

namespace App\Services\Insight\Facts;

use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockUnit;
use App\Services\Insight\Fact;
use App\Services\Insight\FactContext;
use App\Services\Insight\FactResult;
use App\Services\Insight\FactSection;
use App\Support\Locale\Display;

/**
 * Items on hand that expire from today to N days ahead (option days, default 30), by product,
 * soonest first. Items already expired are counted in the note. Quick answers only.
 */
class ExpiringWithin extends Fact
{
    public static function key(): string
    {
        return 'expiring-within';
    }

    public function title(): string
    {
        return __('Expires soon');
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
        $days = max(1, (int) $context->option('days', 30));
        $today = now(config('orthotrack.timezone'))->toDateString();
        $until = now(config('orthotrack.timezone'))->addDays($days)->toDateString();

        $units = $context->scope(StockUnit::query()->onHand(), 'stock.view');
        $batches = $context->scope(StockBatch::query()->onHand(), 'stock.view');

        /** @var array<int, array{quantity: int, first: string}> $byProduct */
        $byProduct = [];

        $add = function ($rows) use (&$byProduct) {
            foreach ($rows as $row) {
                $id = (int) $row->product_id;
                $first = (string) $row->first;
                $byProduct[$id] = [
                    'quantity' => ($byProduct[$id]['quantity'] ?? 0) + (int) $row->n,
                    'first' => isset($byProduct[$id]) ? min($byProduct[$id]['first'], $first) : $first,
                ];
            }
        };

        $add((clone $units)->toBase()->whereBetween('expiry_date', [$today, $until])->groupBy('product_id')
            ->selectRaw('product_id, COUNT(*) AS n, MIN(expiry_date) AS first')->get());
        $add((clone $batches)->toBase()->whereBetween('expiry_date', [$today, $until])->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity) AS n, MIN(expiry_date) AS first')->get());

        $expired = (clone $units)->where('expiry_date', '<', $today)->count()
            + (int) (clone $batches)->where('expiry_date', '<', $today)->sum('quantity');

        uasort($byProduct, fn ($a, $b) => [$a['first'], -$a['quantity']] <=> [$b['first'], -$b['quantity']]);
        $top = array_slice($byProduct, 0, self::MAX_LINES, true);
        $products = Product::query()->whereIn('id', array_keys($top))->get()->keyBy('id');

        $lines = [];

        foreach ($top as $id => $row) {
            $product = $products[$id] ?? null;
            $lines[] = self::line(
                trim(($product->sku ?? '—').' · '.($product->name ?? '')).' · '.Display::date($row['first']),
                self::count($row['quantity']),
            );
        }

        $total = array_sum(array_column($byProduct, 'quantity'));

        return $this->result($context, self::count($total), $lines,
            ['items' => $total, 'products' => count($byProduct), 'days' => $days, 'expired' => $expired],
            'filament.admin.pages.reports.expiry',
            tone: $total > 0 ? FactResult::WARNING : FactResult::GOOD,
            note: trim(__('In the next :days days.', ['days' => self::count($days)]).' '
                .($expired > 0 ? __(':count already expired.', ['count' => self::count($expired)]) : '')));
    }
}
