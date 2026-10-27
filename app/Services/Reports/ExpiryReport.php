<?php

namespace App\Services\Reports;

use App\Enums\StockStatus;
use App\Models\Branch;
use App\Models\ExpiryLevel;
use App\Models\StockBatch;
use App\Models\StockUnit;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;

/**
 * Stock on hand by expiry level (Module 5 / 24): how much is expired, critical, near expiry,
 * and what it cost. Levels are the configurable ones, refreshed every morning.
 */
class ExpiryReport extends Report
{
    public function __construct(private readonly SnapshotConverters $fx) {}

    public static function key(): string
    {
        return 'expiry';
    }

    public function title(): string
    {
        return __('Expiry report');
    }

    public function description(): ?string
    {
        return __('Stock on hand by expiry level, as of this morning\'s check.');
    }

    public function filters(): array
    {
        return ['branch', 'currency', 'group'];
    }

    public function groups(): array
    {
        return [
            'level' => __('Expiry level'),
            'branch_level' => __('Branch and expiry level'),
        ];
    }

    public function defaultFrom(): Carbon
    {
        return now(config('orthotrack.timezone'))->startOfDay();
    }

    public function columns(ReportContext $context): array
    {
        return [
            ...($context->group === 'branch_level' ? [ReportColumn::text('branch', __('Branch'))] : []),
            ReportColumn::text('level', __('Expiry level')),
            ReportColumn::number('units', __('Items')),
            ReportColumn::money('value', __('Value (true cost)')),
        ];
    }

    public function rows(ReportContext $context): array
    {
        $select = fn (string $table, string $count, string $total) => "{$table}.branch_id, {$table}.last_expiry_level_id AS level_id, {$table}.cost_currency, {$table}.cost_fx_snapshot_id, {$count} AS items, {$total} AS total";

        $units = StockUnit::query()
            ->whereIn('status', StockStatus::onHand())
            ->when($context->branchIds !== null, fn ($q) => $q->whereIn('branch_id', $context->branchIds))
            ->groupBy('branch_id', 'last_expiry_level_id', 'cost_currency', 'cost_fx_snapshot_id')
            ->selectRaw($select('stock_units', 'COUNT(*)', 'SUM(unit_cost)'))
            ->toBase()->get();

        $batches = StockBatch::query()
            ->where('quantity', '>', 0)
            ->when($context->branchIds !== null, fn ($q) => $q->whereIn('branch_id', $context->branchIds))
            ->groupBy('branch_id', 'last_expiry_level_id', 'cost_currency', 'cost_fx_snapshot_id')
            ->selectRaw($select('stock_batches', 'SUM(quantity)', 'SUM(quantity * unit_cost)'))
            ->toBase()->get();

        $all = $units->concat($batches);
        $levels = ExpiryLevel::query()->orderBy('sort')->get()->keyBy('id');
        $branches = self::names(Branch::class, $all->pluck('branch_id'));
        $rows = [];

        foreach ($all as $row) {
            $levelId = $row->level_id !== null ? (int) $row->level_id : 0;
            $key = ($context->group === 'branch_level' ? 'b'.$row->branch_id.'-' : '').'l'.$levelId;
            $level = $levels->get($levelId);
            $value = $this->fx->convert((string) $row->total, $row->cost_currency, $context->currency, $row->cost_fx_snapshot_id !== null ? (int) $row->cost_fx_snapshot_id : null, $context) ?? BigDecimal::zero();

            $rows[$key] ??= [
                'key' => $key,
                'branch' => $branches[(int) $row->branch_id] ?? '—',
                'level' => $level !== null ? $level->name : __('No expiry'),
                'sort' => sprintf('%05d-%05d', $context->group === 'branch_level' ? (int) $row->branch_id : 0, $level !== null ? $level->sort : 99999),
                'units' => 0,
                'value' => '0',
            ];
            $rows[$key]['units'] += (int) $row->items;
            $rows[$key]['value'] = self::money(BigDecimal::of($rows[$key]['value'])->plus($value));
        }

        uasort($rows, fn ($a, $b) => strcmp($a['sort'], $b['sort']));

        return array_values($rows);
    }
}
