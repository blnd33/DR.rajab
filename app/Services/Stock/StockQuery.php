<?php

namespace App\Services\Stock;

use App\Enums\StockStatus;
use App\Models\ExpiryLevel;
use App\Models\StockBatch;
use App\Models\StockOnHand;
use App\Models\StockUnit;
use App\Models\User;
use App\Services\Expiry\ExpiryClassifier;
use App\Services\Permissions\BranchScopeResolver;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read side of stock, always limited to the branches the user may see (stock.view).
 */
class StockQuery
{
    public function __construct(
        private readonly BranchScopeResolver $scope,
        private readonly ExpiryClassifier $expiry,
    ) {}

    /**
     * @return list<int>|null null = all branches
     */
    public function branchIds(User $user, string $permission = 'stock.view'): ?array
    {
        return $this->scope->branchIdsFor($user, $permission);
    }

    /**
     * @return Builder<StockUnit>
     */
    public function units(User $user, string $permission = 'stock.view'): Builder
    {
        return $this->scope->apply(StockUnit::query(), $user, $permission);
    }

    /**
     * @return Builder<StockBatch>
     */
    public function batches(User $user, string $permission = 'stock.view'): Builder
    {
        return $this->scope->apply(StockBatch::query(), $user, $permission);
    }

    /**
     * @return Builder<StockOnHand>
     */
    public function onHand(User $user, string $permission = 'stock.view'): Builder
    {
        return $this->scope->apply(StockOnHand::query(), $user, $permission);
    }

    /**
     * Stock value (true cost) and item count per branch and cost currency (nightly snapshot, reports).
     *
     * @param  list<int>|null  $branchIds  null = all
     * @return array<int, array<string, array{value: string, units: int}>>
     */
    public function valueByBranchAndCurrency(?array $branchIds = null): array
    {
        $units = StockUnit::query()
            ->whereIn('status', StockStatus::onHand())
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->groupBy('branch_id', 'cost_currency')
            ->selectRaw('branch_id, cost_currency, SUM(unit_cost) AS total, COUNT(*) AS items')
            ->toBase()->get();

        $batches = StockBatch::query()
            ->where('quantity', '>', 0)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->groupBy('branch_id', 'cost_currency')
            ->selectRaw('branch_id, cost_currency, SUM(quantity * unit_cost) AS total, SUM(quantity) AS items')
            ->toBase()->get();

        $result = [];

        foreach ($units->concat($batches) as $row) {
            $branch = (int) $row->branch_id;
            $currency = (string) ($row->cost_currency ?? 'USD');
            $current = $result[$branch][$currency] ?? ['value' => '0', 'units' => 0];
            $result[$branch][$currency] = [
                'value' => (string) BigDecimal::of($current['value'])->plus(BigDecimal::of((string) ($row->total ?? '0')))->toScale(4, RoundingMode::HalfUp),
                'units' => $current['units'] + (int) $row->items,
            ];
        }

        ksort($result);

        return $result;
    }

    /**
     * Stock value (true cost) per cost currency, e.g. ['USD' => '12400.0000', 'EUR' => '3100.0000'].
     *
     * @param  list<int>|null  $branchIds  null = all
     * @return array<string, string>
     */
    public function valueByCurrency(?array $branchIds): array
    {
        $units = StockUnit::query()
            ->whereIn('status', StockStatus::onHand())
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->groupBy('cost_currency')
            ->selectRaw('cost_currency, SUM(unit_cost) AS total')
            ->pluck('total', 'cost_currency');

        $batches = StockBatch::query()
            ->where('quantity', '>', 0)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->groupBy('cost_currency')
            ->selectRaw('cost_currency, SUM(quantity * unit_cost) AS total')
            ->pluck('total', 'cost_currency');

        $totals = [];

        foreach ([$units, $batches] as $rows) {
            foreach ($rows as $currency => $total) {
                $totals[$currency] = (string) BigDecimal::of($totals[$currency] ?? '0')->plus(BigDecimal::of((string) $total))->toScale(4, RoundingMode::HalfUp);
            }
        }

        ksort($totals);

        return $totals;
    }

    /**
     * Quantities on hand per expiry level and branch (dashboard, Module 6 counts).
     *
     * @param  list<int>|null  $branchIds
     * @return list<array{level: ExpiryLevel, total: int, by_branch: array<int, int>}>
     */
    public function expiryCounts(?array $branchIds): array
    {
        $result = [];

        foreach ($this->expiry->levels() as $level) {
            [$from, $to] = $this->expiry->dateRange($level);

            $byBranch = [];

            $units = StockUnit::query()
                ->whereIn('status', StockStatus::onHand())
                ->whereNotNull('expiry_date')
                ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
                ->when($from !== null, fn ($q) => $q->where('expiry_date', '>=', $from))
                ->when($to !== null, fn ($q) => $q->where('expiry_date', '<=', $to))
                ->groupBy('branch_id')
                ->selectRaw('branch_id, COUNT(*) AS qty')
                ->pluck('qty', 'branch_id');

            $batches = StockBatch::query()
                ->where('quantity', '>', 0)
                ->whereNotNull('expiry_date')
                ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
                ->when($from !== null, fn ($q) => $q->where('expiry_date', '>=', $from))
                ->when($to !== null, fn ($q) => $q->where('expiry_date', '<=', $to))
                ->groupBy('branch_id')
                ->selectRaw('branch_id, SUM(quantity) AS qty')
                ->pluck('qty', 'branch_id');

            foreach ([$units, $batches] as $rows) {
                foreach ($rows as $branchId => $qty) {
                    $byBranch[(int) $branchId] = ($byBranch[(int) $branchId] ?? 0) + (int) $qty;
                }
            }

            $result[] = ['level' => $level, 'total' => array_sum($byBranch), 'by_branch' => $byBranch];
        }

        return $result;
    }

    /**
     * Restrict a units/batches query to an expiry level.
     *
     * @template TModel of StockUnit|StockBatch
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function whereExpiryLevel(Builder $query, ExpiryLevel $level): Builder
    {
        [$from, $to] = $this->expiry->dateRange($level);

        return $query->whereNotNull('expiry_date')
            ->when($from !== null, fn ($q) => $q->where('expiry_date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('expiry_date', '<=', $to));
    }
}
