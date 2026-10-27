<?php

namespace App\Services\Reports;

use App\Enums\StockStatus;
use App\Models\Branch;
use App\Models\Category;
use App\Models\StockBatch;
use App\Models\StockUnit;
use App\Models\StockValueSnapshot;
use Brick\Math\BigDecimal;

/**
 * Stock value at true cost (Module 24): what is on hand now per branch / category, or the
 * nightly history per day. Each cost layer converts with the rates of its purchase.
 */
class StockValueReport extends Report
{
    public function __construct(private readonly SnapshotConverters $fx) {}

    public static function key(): string
    {
        return 'stock-value';
    }

    public function title(): string
    {
        return __('Stock value');
    }

    public function description(): ?string
    {
        return __('Units and lots on hand (shelves, kits, at hospitals, in transit) at their true cost.');
    }

    public function filters(): array
    {
        return ['period', 'branch', 'currency', 'group'];
    }

    public function groups(): array
    {
        return [
            'branch_category' => __('Branch and category'),
            'category' => __('Category'),
            'branch' => __('Branch'),
            'history' => __('History per day (nightly)'),
        ];
    }

    public function columns(ReportContext $context): array
    {
        $columns = match ($context->group) {
            'history' => [ReportColumn::date('day', __('Day')), ReportColumn::text('branch', __('Branch'))],
            'category' => [ReportColumn::text('category', __('Category'))],
            'branch' => [ReportColumn::text('branch', __('Branch'))],
            default => [ReportColumn::text('branch', __('Branch')), ReportColumn::text('category', __('Category'))],
        };

        return [
            ...$columns,
            ReportColumn::number('units', __('Items')),
            ReportColumn::money('value', __('Value (true cost)')),
        ];
    }

    public function rows(ReportContext $context): array
    {
        return $context->group === 'history' ? $this->history($context) : $this->live($context);
    }

    public function totals(ReportContext $context, array $rows): array
    {
        // Adding up the same stock day after day means nothing.
        return $context->group === 'history' ? [] : parent::totals($context, $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function live(ReportContext $context): array
    {
        $units = StockUnit::query()
            ->join('products', 'products.id', '=', 'stock_units.product_id')
            ->whereIn('stock_units.status', StockStatus::onHand())
            ->when($context->branchIds !== null, fn ($q) => $q->whereIn('stock_units.branch_id', $context->branchIds))
            ->groupBy('stock_units.branch_id', 'products.category_id', 'stock_units.cost_currency', 'stock_units.cost_fx_snapshot_id')
            ->selectRaw('stock_units.branch_id, products.category_id, stock_units.cost_currency, stock_units.cost_fx_snapshot_id, COUNT(*) AS items, SUM(stock_units.unit_cost) AS total')
            ->toBase()->get();

        $batches = StockBatch::query()
            ->join('products', 'products.id', '=', 'stock_batches.product_id')
            ->where('stock_batches.quantity', '>', 0)
            ->when($context->branchIds !== null, fn ($q) => $q->whereIn('stock_batches.branch_id', $context->branchIds))
            ->groupBy('stock_batches.branch_id', 'products.category_id', 'stock_batches.cost_currency', 'stock_batches.cost_fx_snapshot_id')
            ->selectRaw('stock_batches.branch_id, products.category_id, stock_batches.cost_currency, stock_batches.cost_fx_snapshot_id, SUM(stock_batches.quantity) AS items, SUM(stock_batches.quantity * stock_batches.unit_cost) AS total')
            ->toBase()->get();

        $all = $units->concat($batches);
        $branches = self::names(Branch::class, $all->pluck('branch_id'));
        $categories = self::names(Category::class, $all->pluck('category_id'));
        $rows = [];

        foreach ($all as $row) {
            $key = match ($context->group) {
                'category' => 'c'.($row->category_id ?? 0),
                'branch' => 'b'.$row->branch_id,
                default => 'b'.$row->branch_id.'-c'.($row->category_id ?? 0),
            };
            $value = $this->fx->convert((string) $row->total, $row->cost_currency, $context->currency, $row->cost_fx_snapshot_id !== null ? (int) $row->cost_fx_snapshot_id : null, $context) ?? BigDecimal::zero();

            $rows[$key] ??= [
                'key' => $key,
                'branch' => $branches[(int) $row->branch_id] ?? '—',
                'category' => $categories[(int) $row->category_id] ?? __('No category'),
                'units' => 0,
                'value' => '0',
            ];
            $rows[$key]['units'] += (int) $row->items;
            $rows[$key]['value'] = self::money(BigDecimal::of($rows[$key]['value'])->plus($value));
        }

        return array_values($rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function history(ReportContext $context): array
    {
        $snapshots = StockValueSnapshot::query()
            ->whereDate('snapshot_date', '>=', $context->from->toDateString())
            ->whereDate('snapshot_date', '<=', $context->until->toDateString())
            ->when($context->branchIds !== null, fn ($q) => $q->whereIn('branch_id', $context->branchIds))
            ->orderBy('snapshot_date')->orderBy('branch_id')
            ->get();

        $branches = self::names(Branch::class, $snapshots->pluck('branch_id'));
        $rows = [];

        foreach ($snapshots as $snapshot) {
            $key = $snapshot->snapshot_date->toDateString().'-'.$snapshot->branch_id;
            $value = $this->fx->convert($snapshot->value, $snapshot->currency, $context->currency, $snapshot->fx_snapshot_id, $context) ?? BigDecimal::zero();

            $rows[$key] ??= ['key' => $key, 'day' => $snapshot->snapshot_date->toDateString(), 'branch' => $branches[$snapshot->branch_id] ?? '—', 'units' => 0, 'value' => '0'];
            $rows[$key]['units'] += $snapshot->units;
            $rows[$key]['value'] = self::money(BigDecimal::of($rows[$key]['value'])->plus($value));
        }

        return array_values($rows);
    }
}
