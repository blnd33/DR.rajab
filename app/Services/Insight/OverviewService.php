<?php

namespace App\Services\Insight;

use App\Enums\BranchStatus;
use App\Enums\Governorate;
use App\Enums\OverviewMetric;
use App\Enums\SalesInvoiceStatus;
use App\Enums\Specialty;
use App\Enums\StockStatus;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\SalesInvoiceItem;
use App\Models\StockBatch;
use App\Models\StockUnit;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;
use App\Services\Reports\SnapshotConverters;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The Main page overview: stations, stock, value, sales and profit per region (Iraq, Kurdistan,
 * both), per governorate and per specialty. Money is in USD at the rates of each purchase / sale
 * (like the reports). Every figure is limited to the stations the user may see for that button.
 *
 * Rows are "facts": one per station × product, with a quantity and an amount; every total on
 * the screens is a sum over the facts that match a region / governorate / specialty.
 */
class OverviewService
{
    public const CURRENCY = 'USD';

    /** @var array<int, ?Specialty>|null product id → specialty */
    private ?array $specialties = null;

    public function __construct(
        private readonly BranchScopeResolver $scope,
        private readonly SnapshotConverters $fx,
    ) {}

    /**
     * @return list<OverviewMetric>
     */
    public function metrics(User $user): array
    {
        return array_values(array_filter(OverviewMetric::cases(), fn (OverviewMetric $m) => $this->allows($user, $m)));
    }

    public function allows(User $user, OverviewMetric $metric): bool
    {
        return $user->can($metric->permission()) || $this->branchIds($user, $metric) !== [];
    }

    /**
     * @return list<int>|null null = every station
     */
    public function branchIds(User $user, OverviewMetric $metric): ?array
    {
        return $this->scope->branchIdsFor($user, $metric->permission());
    }

    /**
     * The stations the user may see for a button, with their governorate.
     *
     * @return Collection<int, Branch>
     */
    public function stations(User $user, OverviewMetric $metric): Collection
    {
        $ids = $this->branchIds($user, $metric);

        return Branch::query()
            ->where('status', '!=', BranchStatus::Closed->value)
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('code')
            ->get();
    }

    /**
     * One fact per station × product: qty (pieces) and amount (the button's number).
     * Stations count stock pieces, like Stock.
     *
     * @return Collection<int, OverviewFact>
     */
    public function facts(User $user, OverviewMetric $metric, Carbon $from, Carbon $until): Collection
    {
        if (! $this->allows($user, $metric)) {
            return collect();
        }

        $ids = $this->branchIds($user, $metric);
        $stations = $this->stations($user, $metric)->keyBy('id');

        $rows = match ($metric) {
            OverviewMetric::Stations, OverviewMetric::Stock => $this->stock($ids, withValue: false),
            OverviewMetric::Value => $this->stock($ids, withValue: true),
            OverviewMetric::Sales, OverviewMetric::Profit => $this->sales($ids, $from, $until, profit: $metric === OverviewMetric::Profit),
        };

        $specialties = $this->specialtiesOf(array_keys(array_reduce($rows, fn ($carry, $r) => $carry + [$r['product_id'] => true], [])));

        return collect($rows)
            ->filter(fn (array $r) => $stations->has($r['branch_id']))
            ->map(fn (array $r) => new OverviewFact(
                branchId: $r['branch_id'],
                productId: $r['product_id'],
                governorate: $stations[$r['branch_id']]->governorate,
                specialty: $specialties[$r['product_id']] ?? null,
                qty: $r['qty'],
                amount: $r['amount'],
                revenue: $r['revenue'] ?? null,
                cost: $r['cost'] ?? null,
            ))
            ->values();
    }

    /**
     * The number a button shows for a set of facts: pieces for stock, USD for money,
     * stations holding something for Stations.
     *
     * @param  Collection<int, OverviewFact>  $facts
     */
    public function total(OverviewMetric $metric, Collection $facts): string
    {
        return match ($metric) {
            OverviewMetric::Stock => (string) $facts->sum('qty'),
            OverviewMetric::Stations => (string) $facts->where('qty', '>', 0)->pluck('branchId')->unique()->count(),
            default => (string) $facts->reduce(fn (BigDecimal $sum, OverviewFact $f) => $sum->plus($f->amount), BigDecimal::zero())->toScale(2, RoundingMode::HalfUp),
        };
    }

    /**
     * @param  Collection<int, OverviewFact>  $facts
     * @return Collection<int, OverviewFact>
     */
    public function filter(Collection $facts, ?string $region = null, ?Governorate $governorate = null, ?Specialty $specialty = null): Collection
    {
        return $facts->filter(fn (OverviewFact $f) => ($region === null || $f->governorate?->region() === $region)
            && ($region !== null || $governorate !== null || $f->governorate !== null)
            && ($governorate === null || $f->governorate === $governorate)
            && ($specialty === null || $f->specialty === $specialty))->values();
    }

    /**
     * Product id → specialty: the category's own, else the nearest parent's.
     *
     * @param  list<int>  $productIds
     * @return array<int, ?Specialty>
     */
    public function specialtiesOf(array $productIds): array
    {
        $this->specialties ??= [];
        $missing = array_values(array_diff($productIds, array_keys($this->specialties)));

        if ($missing !== []) {
            $categories = Category::query()->get(['id', 'parent_id', 'specialty'])->keyBy('id');
            $resolve = function (?int $id) use ($categories): ?Specialty {
                for ($guard = 0; $id !== null && $guard < 10; $guard++) {
                    $category = $categories->get($id);

                    if ($category === null) {
                        return null;
                    }

                    if ($category->specialty !== null) {
                        return $category->specialty;
                    }

                    $id = $category->parent_id;
                }

                return null;
            };

            foreach (Product::query()->whereIn('id', $missing)->pluck('category_id', 'id') as $productId => $categoryId) {
                $this->specialties[(int) $productId] = $resolve($categoryId !== null ? (int) $categoryId : null);
            }
        }

        return array_intersect_key($this->specialties, array_flip($productIds));
    }

    /**
     * Pieces (and their true cost in USD) on hand: shelves, instrument sets, hospitals, transit.
     *
     * @param  list<int>|null  $branchIds
     * @return list<array{branch_id: int, product_id: int, qty: int, amount: string}>
     */
    private function stock(?array $branchIds, bool $withValue): array
    {
        $units = StockUnit::query()
            ->whereIn('status', StockStatus::onHand())
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->groupBy('branch_id', 'product_id', 'cost_currency', 'cost_fx_snapshot_id')
            ->selectRaw('branch_id, product_id, cost_currency, cost_fx_snapshot_id, COUNT(*) AS items, SUM(unit_cost) AS total')
            ->toBase()->get();

        $batches = StockBatch::query()
            ->where('quantity', '>', 0)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->groupBy('branch_id', 'product_id', 'cost_currency', 'cost_fx_snapshot_id')
            ->selectRaw('branch_id, product_id, cost_currency, cost_fx_snapshot_id, SUM(quantity) AS items, SUM(quantity * unit_cost) AS total')
            ->toBase()->get();

        $rows = [];

        foreach ($units->concat($batches) as $row) {
            $key = $row->branch_id.'-'.$row->product_id;
            $value = $withValue
                ? ($this->fx->convert((string) ($row->total ?? '0'), $row->cost_currency, self::CURRENCY, $row->cost_fx_snapshot_id !== null ? (int) $row->cost_fx_snapshot_id : null) ?? BigDecimal::zero())
                : BigDecimal::of((int) $row->items);

            $rows[$key] ??= ['branch_id' => (int) $row->branch_id, 'product_id' => (int) $row->product_id, 'qty' => 0, 'amount' => '0'];
            $rows[$key]['qty'] += (int) $row->items;
            $rows[$key]['amount'] = (string) BigDecimal::of($rows[$key]['amount'])->plus($value)->toScale(4, RoundingMode::HalfUp);
        }

        return array_values($rows);
    }

    /**
     * What was sold in the period (after the invoice discount) and its profit, in USD. Voided
     * and draft sales don't count — the same rules as the Sales and profit report.
     *
     * @param  list<int>|null  $branchIds
     * @return list<array{branch_id: int, product_id: int, qty: int, amount: string, revenue: string, cost: string}>
     */
    private function sales(?array $branchIds, Carbon $from, Carbon $until, bool $profit): array
    {
        $items = SalesInvoiceItem::query()
            ->join('sales_invoices', 'sales_invoices.id', '=', 'sales_invoice_items.sales_invoice_id')
            ->whereNotIn('sales_invoices.status', [SalesInvoiceStatus::Draft->value, SalesInvoiceStatus::Voided->value])
            ->whereBetween('sales_invoices.issued_at', [$from->copy()->utc(), $until->copy()->utc()])
            ->when($branchIds !== null, fn ($q) => $q->whereIn('sales_invoices.branch_id', $branchIds))
            ->select([
                'sales_invoice_items.product_id', 'sales_invoice_items.quantity', 'sales_invoice_items.line_total',
                'sales_invoice_items.unit_cost', 'sales_invoice_items.cost_currency', 'sales_invoice_items.cost_fx_snapshot_id',
                'sales_invoices.branch_id', 'sales_invoices.currency AS invoice_currency', 'sales_invoices.fx_snapshot_id AS invoice_snapshot_id',
                'sales_invoices.subtotal', 'sales_invoices.total',
            ])
            ->toBase()->get();

        $rows = [];

        foreach ($items as $item) {
            $subtotal = BigDecimal::of((string) $item->subtotal);
            $line = BigDecimal::of((string) $item->line_total);
            $net = $subtotal->isZero() ? $line : $line->multipliedBy((string) $item->total)->dividedBy($subtotal, 4, RoundingMode::HalfUp);
            $revenue = $this->fx->convert((string) $net, $item->invoice_currency, self::CURRENCY, (int) $item->invoice_snapshot_id) ?? BigDecimal::zero();
            $cost = $this->fx->convert((string) BigDecimal::of((string) ($item->unit_cost ?? '0'))->multipliedBy((int) $item->quantity),
                $item->cost_currency ?? $item->invoice_currency, self::CURRENCY,
                $item->cost_fx_snapshot_id !== null ? (int) $item->cost_fx_snapshot_id : (int) $item->invoice_snapshot_id) ?? BigDecimal::zero();

            $key = $item->branch_id.'-'.$item->product_id;
            $rows[$key] ??= ['branch_id' => (int) $item->branch_id, 'product_id' => (int) $item->product_id, 'qty' => 0, 'amount' => '0', 'revenue' => '0', 'cost' => '0'];
            $rows[$key]['qty'] += (int) $item->quantity;
            $rows[$key]['revenue'] = (string) BigDecimal::of($rows[$key]['revenue'])->plus($revenue)->toScale(4, RoundingMode::HalfUp);
            $rows[$key]['cost'] = (string) BigDecimal::of($rows[$key]['cost'])->plus($cost)->toScale(4, RoundingMode::HalfUp);
            $rows[$key]['amount'] = $profit
                ? (string) BigDecimal::of($rows[$key]['revenue'])->minus($rows[$key]['cost'])
                : $rows[$key]['revenue'];
        }

        return array_values($rows);
    }
}
