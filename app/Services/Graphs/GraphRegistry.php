<?php

namespace App\Services\Graphs;

use App\Enums\SalesInvoiceStatus;
use App\Enums\SurgeryStatus;
use App\Enums\TransferStatus;
use App\Enums\UsageLevel;
use App\Enums\WriteOffReason;
use App\Enums\WriteOffStatus;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\SurgeryItem;
use App\Models\Transfer;
use App\Models\User;
use App\Models\WriteOff;
use App\Services\Permissions\BranchScopeResolver;
use App\Services\Reports\ExpensesReport;
use App\Services\Reports\ExpiryReport;
use App\Services\Reports\KitsReport;
use App\Services\Reports\Report;
use App\Services\Reports\SalesProfitReport;
use App\Services\Reports\SnapshotConverters;
use App\Services\Reports\StockMovementsReport;
use App\Services\Reports\StockValueReport;
use App\Services\Reports\SupplierAgingReport;
use App\Services\Reports\SurgeriesReport;
use App\Support\Locale\Display;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;

/**
 * Every graph of the Graphs page (admin). Graphs draw the reports' own rows wherever a report
 * exists, so a graph never disagrees with its report; the rest have small queries here.
 * Same access as the reports: reports.view in some branch, cost / profit with reports.profit.
 */
final class GraphRegistry
{
    /** Pie slices before the rest folds into "Other". */
    public const MAX_SLICES = 7;

    /** Bars in a ranking. */
    public const MAX_BARS = 10;

    public function __construct(
        private readonly SnapshotConverters $fx,
    ) {}

    public static function canUse(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'reports.view');
    }

    public function canSee(User $user, Graph $graph): bool
    {
        return self::canUse($user) && (! $graph->needsProfit || $user->can('reports.profit'));
    }

    /**
     * @return list<Graph>
     */
    public function visibleTo(User $user): array
    {
        return array_values(array_filter($this->all(), fn (Graph $g) => $this->canSee($user, $g)));
    }

    public function find(string $key): ?Graph
    {
        foreach ($this->all() as $graph) {
            if ($graph->key === $key) {
                return $graph;
            }
        }

        return null;
    }

    /**
     * Graphs whose title, description, section or keywords contain every word typed, in any
     * of the three languages (a doctor may search "مبيعات" or "sales").
     *
     * @return list<Graph>
     */
    public function search(User $user, string $query = '', ?GraphSection $section = null): array
    {
        $graphs = array_values(array_filter($this->visibleTo($user), fn (Graph $g) => $section === null || $g->section === $section));
        $words = array_values(array_filter(preg_split('/\s+/u', mb_strtolower(trim($query))) ?: [], fn ($w) => $w !== ''));

        if ($words === []) {
            return $graphs;
        }

        $haystacks = $this->haystacks();

        return array_values(array_filter($graphs, function (Graph $graph) use ($words, $haystacks) {
            $haystack = $haystacks[$graph->key] ?? '';

            foreach ($words as $word) {
                if (! str_contains($haystack, $word)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Runs the graph and shapes it for its kind: pies sorted with "Other", rankings cut to the
     * top 10, months in order.
     */
    public function build(Graph $graph, GraphContext $context): GraphData
    {
        $data = ($graph->build)($context);

        return match ($graph->kind) {
            Graph::DOUGHNUT => $this->fold($data),
            Graph::RANKING => $this->top($data),
            default => $data,
        };
    }

    /**
     * @return list<Graph>
     */
    public function all(): array
    {
        return [
            // Sales
            new Graph('sales-per-month', GraphSection::Sales, __('Sales per month'), __('Revenue after discounts each month; the cost of what was sold too when you may see profit.'), Graph::LINE,
                function (GraphContext $c) {
                    $series = ['revenue' => __('Revenue')] + ($c->user->can('reports.profit') ? ['cost' => __('Cost')] : []);

                    return $this->fromReport($c, SalesProfitReport::class, 'month', 'month', $series, true, months: true);
                }, ['revenue', 'income', 'trend', 'money'], usesCurrency: true),
            new Graph('profit-per-month', GraphSection::Sales, __('Profit per month'), __('Revenue minus the true cost of the items sold, each month.'), Graph::MONTHS,
                fn (GraphContext $c) => $this->fromReport($c, SalesProfitReport::class, 'month', 'month', ['profit' => __('Profit')], true, months: true),
                ['margin', 'earnings'], needsProfit: true, usesCurrency: true),
            new Graph('sales-by-branch', GraphSection::Sales, __('Sales by branch'), __('Share of the revenue made by each branch.'), Graph::DOUGHNUT,
                fn (GraphContext $c) => $this->fromReport($c, SalesProfitReport::class, 'branch', 'branch', ['revenue' => __('Revenue')], true),
                ['revenue', 'branches'], usesCurrency: true),
            new Graph('sales-by-customer', GraphSection::Sales, __('Sales by hospital / customer'), __('Who bought the most: share of the revenue per hospital or customer.'), Graph::DOUGHNUT,
                fn (GraphContext $c) => $this->fromReport($c, SalesProfitReport::class, 'customer', 'customer', ['revenue' => __('Revenue')], true),
                ['hospital', 'customers', 'clients'], usesCurrency: true),
            new Graph('sales-by-item', GraphSection::Sales, __('Sales by item'), __('Share of the revenue brought in by each sold item.'), Graph::DOUGHNUT,
                fn (GraphContext $c) => $this->fromReport($c, SalesProfitReport::class, 'product', 'product', ['revenue' => __('Revenue')], true),
                ['items', 'products', 'sold', 'implants'], usesCurrency: true),
            new Graph('top-sold-items', GraphSection::Sales, __('Most sold items'), __('The ten items sold in the largest quantities.'), Graph::RANKING,
                fn (GraphContext $c) => $this->fromReport($c, SalesProfitReport::class, 'product', 'product', ['quantity' => __('Quantity')], false),
                ['top', 'items', 'products', 'best']),
            new Graph('sales-by-currency', GraphSection::Sales, __('Sales by currency'), __('How much was sold in US dollars, euros and Iraqi dinars, all shown in one currency.'), Graph::DOUGHNUT,
                fn (GraphContext $c) => $this->salesByCurrency($c),
                ['usd', 'eur', 'iqd', 'dollar', 'euro', 'dinar'], usesCurrency: true),

            // Surgeries
            new Graph('surgeries-per-month', GraphSection::Surgeries, __('Surgeries per month'), __('Completed surgeries each month.'), Graph::MONTHS,
                fn (GraphContext $c) => $this->fromReport($c, SurgeriesReport::class, 'month', 'label', ['surgeries' => __('Surgeries')], false, months: true),
                ['operations', 'cases', 'doctor']),
            new Graph('surgeries-by-type', GraphSection::Surgeries, __('Surgeries by type'), __('Share of each surgery type (hip, knee, shoulder…).'), Graph::DOUGHNUT,
                fn (GraphContext $c) => $this->fromReport($c, SurgeriesReport::class, 'type', 'label', ['surgeries' => __('Surgeries')], false),
                ['operations', 'hip', 'knee', 'shoulder']),
            new Graph('surgeries-by-hospital', GraphSection::Surgeries, __('Surgeries by hospital'), __('Where the surgeries took place.'), Graph::DOUGHNUT,
                fn (GraphContext $c) => $this->fromReport($c, SurgeriesReport::class, 'hospital', 'label', ['surgeries' => __('Surgeries')], false),
                ['operations', 'hospitals']),
            new Graph('surgeries-by-surgeon', GraphSection::Surgeries, __('Surgeries by lead surgeon'), __('Surgeries done by each lead surgeon.'), Graph::RANKING,
                fn (GraphContext $c) => $this->fromReport($c, SurgeriesReport::class, 'doctor', 'label', ['surgeries' => __('Surgeries')], false),
                ['doctor', 'surgeon', 'operations']),
            new Graph('implants-per-month', GraphSection::Surgeries, __('Implants used per month'), __('Implants used in completed surgeries each month.'), Graph::MONTHS,
                fn (GraphContext $c) => $this->fromReport($c, SurgeriesReport::class, 'month', 'label', ['implants' => __('Implants')], false, months: true),
                ['items', 'used', 'consumption']),
            new Graph('most-used-implants', GraphSection::Surgeries, __('Most used implants'), __('The ten implants used most in surgeries.'), Graph::RANKING,
                fn (GraphContext $c) => $this->mostUsedImplants($c),
                ['top', 'items', 'products', 'consumption']),
            new Graph('implant-cost-per-month', GraphSection::Surgeries, __('Implant cost per month'), __('The cost of the implants used in surgeries each month.'), Graph::LINE,
                fn (GraphContext $c) => $this->fromReport($c, SurgeriesReport::class, 'month', 'label', ['implant_cost' => __('Implant cost')], true, months: true),
                ['money', 'items'], needsProfit: true, usesCurrency: true),

            // Items and stock
            new Graph('stock-value-by-branch', GraphSection::Stock, __('Stock value by branch'), __('What the stock on hand is worth in each branch today (true cost).'), Graph::DOUGHNUT,
                fn (GraphContext $c) => $this->fromReport($c, StockValueReport::class, 'branch', 'branch', ['value' => __('Stock value')], true),
                ['inventory', 'worth', 'branches'], usesPeriod: false, usesCurrency: true),
            new Graph('stock-value-by-category', GraphSection::Stock, __('Stock value by category'), __('What the stock on hand is worth per category today.'), Graph::DOUGHNUT,
                fn (GraphContext $c) => $this->fromReport($c, StockValueReport::class, 'category', 'category', ['value' => __('Stock value')], true),
                ['inventory', 'worth', 'categories', 'hip', 'knee', 'shoulder'], usesPeriod: false, usesCurrency: true),
            new Graph('stock-value-history', GraphSection::Stock, __('Stock value over time'), __('The stock value every night (saved at 23:50).'), Graph::LINE,
                fn (GraphContext $c) => $this->fromReport($c, StockValueReport::class, 'history', 'day', ['value' => __('Stock value')], true),
                ['inventory', 'trend', 'history'], usesCurrency: true, summable: false),
            new Graph('items-by-branch', GraphSection::Stock, __('Items on hand by branch'), __('How many pieces each branch holds today.'), Graph::RANKING,
                fn (GraphContext $c) => $this->fromReport($c, StockValueReport::class, 'branch', 'branch', ['units' => __('Pieces')], false),
                ['inventory', 'quantity', 'branches'], usesPeriod: false),
            new Graph('items-by-expiry', GraphSection::Stock, __('Items by expiry level'), __('Pieces on hand per expiry level (good, soon, expired…).'), Graph::DOUGHNUT,
                fn (GraphContext $c) => $this->fromReport($c, ExpiryReport::class, 'level', 'level', ['units' => __('Pieces')], false),
                ['expiry', 'expired', 'dates'], usesPeriod: false),
            new Graph('catalog-by-usage', GraphSection::Stock, __('Catalogue by usage'), __('The doctor\'s items by how often they are used (the colours of his item list).'), Graph::DOUGHNUT,
                fn (GraphContext $c) => $this->catalogByUsage(),
                ['items', 'products', 'lima', 'colours', 'most used'], usesPeriod: false),
            new Graph('catalog-by-area', GraphSection::Stock, __('Catalogue by area'), __('The doctor\'s items per area (hip, knee, shoulder…).'), Graph::DOUGHNUT,
                fn (GraphContext $c) => $this->catalogByArea(),
                ['items', 'products', 'categories', 'hip', 'knee', 'shoulder'], usesPeriod: false),
            new Graph('movements-by-type', GraphSection::Stock, __('Stock movements by type'), __('Received, sent, sold, used in surgery… how often stock moved, by kind.'), Graph::DOUGHNUT,
                fn (GraphContext $c) => $this->fromReport($c, StockMovementsReport::class, 'type', 'type', ['movements' => __('Movements')], false),
                ['ledger', 'received', 'sold']),
            new Graph('write-offs-by-reason', GraphSection::Stock, __('Write-offs by reason'), __('Approved write-offs: damaged, lost, expired, other.'), Graph::DOUGHNUT,
                fn (GraphContext $c) => $this->writeOffsByReason($c),
                ['damaged', 'lost', 'expired', 'loss']),

            // Money
            new Graph('expenses-by-category', GraphSection::Money, __('Expenses by category'), __('Where the money went: share of the expenses per category.'), Graph::DOUGHNUT,
                fn (GraphContext $c) => $this->fromReport($c, ExpensesReport::class, 'category', 'category', ['amount' => __('Amount')], true),
                ['costs', 'spending'], usesCurrency: true),
            new Graph('expenses-per-month', GraphSection::Money, __('Expenses per month'), __('Total expenses each month.'), Graph::MONTHS,
                fn (GraphContext $c) => $this->fromReport($c, ExpensesReport::class, 'month', 'month', ['amount' => __('Amount')], true, months: true),
                ['costs', 'spending', 'trend'], usesCurrency: true),
            new Graph('expenses-by-branch', GraphSection::Money, __('Expenses by branch'), __('Share of the expenses carried by each branch.'), Graph::DOUGHNUT,
                fn (GraphContext $c) => $this->fromReport($c, ExpensesReport::class, 'branch', 'branch', ['amount' => __('Amount')], true),
                ['costs', 'spending', 'branches'], usesCurrency: true),
            new Graph('supplier-debt', GraphSection::Money, __('What we owe each supplier'), __('Open supplier invoices today, largest first.'), Graph::RANKING,
                fn (GraphContext $c) => $this->fromReport($c, SupplierAgingReport::class, null, 'supplier', ['open' => __('Open')], true),
                ['suppliers', 'debt', 'payable', 'aging'], usesPeriod: false, usesCurrency: true),

            // Transfers
            new Graph('transfers-per-month', GraphSection::Transfers, __('Transfers per month'), __('Transfers sent between branches each month.'), Graph::MONTHS,
                fn (GraphContext $c) => $this->transfersPerMonth($c),
                ['moves', 'shipping', 'branches']),
            new Graph('transfers-by-route', GraphSection::Transfers, __('Transfers by route'), __('Which branch sends to which branch most often.'), Graph::RANKING,
                fn (GraphContext $c) => $this->transfersByRoute($c),
                ['moves', 'from', 'to', 'branches']),
            new Graph('transfer-results', GraphSection::Transfers, __('Transfer results'), __('Received in full, received with differences, still on the way, cancelled.'), Graph::DOUGHNUT,
                fn (GraphContext $c) => $this->transferResults($c),
                ['status', 'missing', 'differences']),

            // Kits
            new Graph('kit-checkouts', GraphSection::Kits, __('Kit check-outs'), __('How often each kit went out to a hospital.'), Graph::RANKING,
                fn (GraphContext $c) => $this->fromReport($c, KitsReport::class, null, 'code', ['checkouts' => __('Check-outs')], false),
                ['sets', 'instruments', 'loans']),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | From a report
    |--------------------------------------------------------------------------
    */

    /**
     * @param  class-string<Report>  $class
     * @param  array<string, string>  $series  row column → series label
     */
    private function fromReport(GraphContext $c, string $class, ?string $group, string $labelKey, array $series, bool $money, bool $months = false): GraphData
    {
        $report = app($class);
        $rows = $report->rows($c->report($report, $group));
        $values = [];

        // Rows with the same label (e.g. one per branch on a history day) add up.
        foreach ($rows as $row) {
            $label = (string) ($row[$labelKey] ?? '—');

            foreach (array_keys($series) as $column) {
                $values[$label][$column] = ($values[$label][$column] ?? 0.0) + (float) ($row[$column] ?? 0);
            }
        }

        if ($months) {
            $values = array_replace(array_fill_keys($c->months(), []), $values);
        }

        if ($labelKey === 'day' || $months) {
            ksort($values);
        }

        $labels = array_map('strval', array_keys($values));

        return new GraphData(
            labels: $months || $labelKey === 'day' ? array_map(fn ($l) => Display::digits($l), $labels) : $labels,
            series: array_map(fn (string $column, string $label) => [
                'label' => $label,
                'values' => array_map(fn (string $l) => round((float) ($values[$l][$column] ?? 0), 2), $labels),
            ], array_keys($series), array_values($series)),
            currency: $money ? $c->currency : null,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Own queries
    |--------------------------------------------------------------------------
    */

    private function salesByCurrency(GraphContext $c): GraphData
    {
        $report = $c->report(app(SalesProfitReport::class));
        $totals = [];

        SalesInvoice::query()
            ->whereNotIn('status', [SalesInvoiceStatus::Draft->value, SalesInvoiceStatus::Voided->value])
            ->whereBetween('issued_at', $c->bounds())
            ->when($c->branchIds !== null, fn ($q) => $q->whereIn('branch_id', $c->branchIds))
            ->select(['id', 'currency', 'total', 'fx_snapshot_id'])
            ->each(function (SalesInvoice $invoice) use (&$totals, $c, $report) {
                $amount = $this->fx->convert((string) $invoice->total, $invoice->currency, $c->currency, $invoice->fx_snapshot_id, $report) ?? BigDecimal::zero();
                $totals[$invoice->currency] = ($totals[$invoice->currency] ?? 0.0) + $amount->toFloat();
            });

        return $this->simple($totals, __('Revenue'), $c->currency);
    }

    private function mostUsedImplants(GraphContext $c): GraphData
    {
        $rows = SurgeryItem::query()
            ->join('surgeries', 'surgeries.id', '=', 'surgery_items.surgery_id')
            ->where('surgeries.status', SurgeryStatus::Completed->value)
            ->whereBetween('surgeries.surgery_date', [$c->from->toDateString(), $c->until->toDateString()])
            ->when($c->branchIds !== null, fn ($q) => $q->whereIn('surgeries.branch_id', $c->branchIds))
            ->whereNotNull('surgery_items.used_at')->whereNull('surgery_items.reversed_at')
            ->groupBy('surgery_items.product_id')
            ->selectRaw('surgery_items.product_id, SUM(surgery_items.quantity) AS used')
            ->toBase()->get();
        $products = Product::query()->whereIn('id', $rows->pluck('product_id'))->get()->keyBy('id');
        $totals = [];

        foreach ($rows as $row) {
            $product = $products[$row->product_id] ?? null;
            $totals[$product !== null ? $product->sku.' · '.$product->name : '—'] = (float) $row->used;
        }

        return $this->simple($totals, __('Used'));
    }

    private function catalogByUsage(): GraphData
    {
        $counts = Product::query()->active()->groupBy('usage_level')->selectRaw('usage_level, COUNT(*) AS items')->toBase()->get();
        $totals = [];

        // In usage order, most used first.
        foreach ([...UsageLevel::cases(), null] as $level) {
            $count = (float) ($counts->first(fn ($r) => $r->usage_level === $level?->value)->items ?? 0);

            if ($count > 0) {
                $totals[$level?->getLabel() ?? __('Not set')] = $count;
            }
        }

        return $this->simple($totals, __('Items'));
    }

    private function catalogByArea(): GraphData
    {
        $categories = Category::query()->get(['id', 'parent_id', 'name'])->keyBy('id');
        $root = function (?int $id) use ($categories): ?Category {
            $category = $id !== null ? $categories->get($id) : null;

            for ($guard = 0; $category?->parent_id !== null && $guard < 10; $guard++) {
                $category = $categories->get($category->parent_id) ?? $category;
            }

            return $category;
        };
        $totals = [];

        foreach (Product::query()->active()->groupBy('category_id')->selectRaw('category_id, COUNT(*) AS items')->toBase()->get() as $row) {
            $label = $root($row->category_id !== null ? (int) $row->category_id : null)->name ?? __('No category');
            $totals[$label] = ($totals[$label] ?? 0.0) + (float) $row->items;
        }

        return $this->simple($totals, __('Items'));
    }

    private function writeOffsByReason(GraphContext $c): GraphData
    {
        $rows = WriteOff::query()
            ->where('status', WriteOffStatus::Approved->value)
            ->whereBetween('reported_at', $c->bounds())
            ->when($c->branchIds !== null, fn ($q) => $q->whereIn('branch_id', $c->branchIds))
            ->groupBy('reason')->selectRaw('reason, COUNT(*) AS n')->toBase()->get();
        $totals = [];

        foreach ($rows as $row) {
            $totals[WriteOffReason::tryFrom((string) $row->reason)?->getLabel() ?? (string) $row->reason] = (float) $row->n;
        }

        return $this->simple($totals, __('Write-offs'));
    }

    /**
     * @return Builder<Transfer>
     */
    private function transfers(GraphContext $c, string $date): Builder
    {
        return Transfer::query()
            ->whereBetween($date, $c->bounds())
            ->when($c->branchIds !== null, fn ($q) => $q->where(fn ($w) => $w->whereIn('from_branch_id', $c->branchIds)->orWhereIn('to_branch_id', $c->branchIds)));
    }

    private function transfersPerMonth(GraphContext $c): GraphData
    {
        $tz = config('orthotrack.timezone');
        $totals = array_fill_keys($c->months(), 0.0);

        foreach ($this->transfers($c, 'sent_at')->whereNotNull('sent_at')->pluck('sent_at') as $sentAt) {
            $month = Carbon::parse($sentAt)->setTimezone($tz)->format('Y-m');

            if (array_key_exists($month, $totals)) {
                $totals[$month]++;
            }
        }

        return new GraphData(array_map(fn ($m) => Display::digits($m), array_keys($totals)), [['label' => __('Transfers'), 'values' => array_values($totals)]]);
    }

    private function transfersByRoute(GraphContext $c): GraphData
    {
        $rows = $this->transfers($c, 'sent_at')->whereNotNull('sent_at')
            ->groupBy('from_branch_id', 'to_branch_id')->selectRaw('from_branch_id, to_branch_id, COUNT(*) AS n')->toBase()->get();
        $codes = Branch::query()->pluck('code', 'id');
        $totals = [];

        foreach ($rows as $row) {
            $totals[($codes[$row->from_branch_id] ?? '?').' → '.($codes[$row->to_branch_id] ?? '?')] = (float) $row->n;
        }

        return $this->simple($totals, __('Transfers'));
    }

    private function transferResults(GraphContext $c): GraphData
    {
        $rows = $this->transfers($c, 'created_at')->where('status', '<>', TransferStatus::Draft->value)
            ->groupBy('status')->selectRaw('status, COUNT(*) AS n')->toBase()->get();
        $totals = [];

        foreach ($rows as $row) {
            $totals[TransferStatus::tryFrom((string) $row->status)?->getLabel() ?? (string) $row->status] = (float) $row->n;
        }

        return $this->simple($totals, __('Transfers'));
    }

    /**
     * @param  array<string, float>  $totals
     */
    private function simple(array $totals, string $series, ?string $currency = null): GraphData
    {
        return new GraphData(array_map('strval', array_keys($totals)), [['label' => $series, 'values' => array_map(fn ($v) => round((float) $v, 2), array_values($totals))]], $currency);
    }

    /*
    |--------------------------------------------------------------------------
    | Shaping
    |--------------------------------------------------------------------------
    */

    /**
     * Largest first; past seven slices the rest is one grey "Other".
     */
    /**
     * The graph's numbers as a pie: the first series, largest first, past 7 slices folded into
     * "Other". Null with the reason when a pie would mislead.
     *
     * @return array{0: ?GraphData, 1: ?string}
     */
    public function asPie(Graph $graph, GraphData $data): array
    {
        if (! $graph->summable) {
            return [null, __('These values do not add up to a whole, so a pie would mislead.')];
        }

        $values = $data->values();

        if (min($values ?: [0]) < 0) {
            return [null, __('A pie cannot show negative numbers.')];
        }

        if (array_sum($values) <= 0) {
            return [null, __('Nothing to draw for these filters')];
        }

        if (in_array(true, $data->other, true)) {
            // Already a pie's numbers (folded): keep them, first series only.
            return [new GraphData($data->labels, [$data->series[0]], $data->currency, $data->other), null];
        }

        return [$this->fold($data), null];
    }

    private function fold(GraphData $data): GraphData
    {
        $pairs = $this->sorted($data);
        $other = [];

        if (count($pairs) > self::MAX_SLICES + 1) {
            $rest = array_splice($pairs, self::MAX_SLICES);
            $pairs[] = [__('Other'), array_sum(array_column($rest, 1))];
            $other = array_merge(array_fill(0, self::MAX_SLICES, false), [true]);
        }

        return new GraphData(array_column($pairs, 0), [['label' => $data->series[0]['label'] ?? '', 'values' => array_column($pairs, 1)]], $data->currency, $other);
    }

    private function top(GraphData $data): GraphData
    {
        $pairs = array_slice($this->sorted($data), 0, self::MAX_BARS);

        return new GraphData(array_column($pairs, 0), [['label' => $data->series[0]['label'] ?? '', 'values' => array_column($pairs, 1)]], $data->currency);
    }

    /**
     * Non-zero values of the first series, largest first.
     *
     * @return list<array{0: string, 1: float}>
     */
    private function sorted(GraphData $data): array
    {
        $pairs = [];

        foreach ($data->labels as $i => $label) {
            $value = (float) ($data->series[0]['values'][$i] ?? 0);

            if ($value != 0) {
                $pairs[] = [$label, $value];
            }
        }

        usort($pairs, fn ($a, $b) => $b[1] <=> $a[1]);

        return $pairs;
    }

    /**
     * Search text per graph in every language.
     *
     * @return array<string, string>
     */
    private function haystacks(): array
    {
        $current = App::getLocale();
        $haystacks = [];

        try {
            foreach (array_keys(config('locales.supported', ['en' => []])) as $locale) {
                App::setLocale($locale);

                foreach ($this->all() as $graph) {
                    $haystacks[$graph->key] = ($haystacks[$graph->key] ?? '').' '.mb_strtolower(implode(' ', [
                        $graph->title, $graph->description, $graph->section->label(), $graph->kindLabel(), implode(' ', $graph->keywords),
                    ]));
                }
            }
        } finally {
            App::setLocale($current);
        }

        return $haystacks;
    }
}
