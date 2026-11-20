<?php

namespace App\Filament\Admin\Pages;

use App\Enums\Governorate;
use App\Enums\OverviewMetric;
use App\Enums\Specialty;
use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
use App\Services\Insight\OverviewFact;
use App\Services\Insight\OverviewService;
use App\Support\CompanyContext;
use App\Support\Locale\Display;
use Filament\Pages\Page;
use Filament\Panel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * Opened from the big buttons on the Home page.
 *
 *  - A button of the second row (Stations, Stock, Value, Sales, Profit): Iraq / Kurdistan / both,
 *    then the governorates (green = something there, red = nothing; the company's own: all 19 for
 *    KurdishMedicus, Baghdad / Karbala / Babil for Al-Fadhaa' Bureau), then a specialty, then
 *    what is there product by product.
 *  - A specialty of the first row: every button for that specialty, per region and governorate,
 *    then one governorate product by product.
 *
 * Sales and profit follow the chosen period; stations, stock and value are as of now.
 */
class OverviewPage extends Page
{
    protected string $view = 'filament.pages.overview';

    protected static bool $shouldRegisterNavigation = false;

    /** Second-row button (stations, stock, value, sales, profit). */
    #[Url]
    public ?string $metric = null;

    /** First-row button (a specialty), when no metric was chosen. */
    #[Url]
    public ?string $focus = null;

    #[Url]
    public ?string $city = null;

    /** Specialty chosen inside a governorate (second-row buttons). */
    #[Url]
    public ?string $specialty = null;

    #[Url]
    public string $period = 'month';

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $until = null;

    public const PERIODS = ['today', 'month', 'year', 'all', 'custom'];

    public static function getSlug(?Panel $panel = null): string
    {
        return 'overview';
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && app(OverviewService::class)->metrics($user) !== [];
    }

    public function mount(): void
    {
        $user = $this->user();
        $metrics = app(OverviewService::class)->metrics($user);

        $this->metric = OverviewMetric::tryFrom((string) $this->metric) !== null && in_array(OverviewMetric::from($this->metric), $metrics, true) ? $this->metric : null;
        $this->focus = $this->metric === null ? Specialty::tryFrom((string) $this->focus)?->value : null;

        if ($this->metric === null && $this->focus === null) {
            $this->metric = $metrics[0]->value;
        }

        $this->city = Governorate::tryFrom((string) $this->city)?->value;
        $this->specialty = Specialty::tryFrom((string) $this->specialty)?->value;
        $this->period = in_array($this->period, self::PERIODS, true) ? $this->period : 'month';
    }

    public function getTitle(): string
    {
        return $this->metricCase()?->getLabel() ?? $this->focusCase()?->getLabel() ?? __('Overview');
    }

    public function openCity(string $city): void
    {
        $this->city = Governorate::tryFrom($city)?->value;
        $this->specialty = null;
    }

    public function openSpecialty(string $specialty): void
    {
        $this->specialty = Specialty::tryFrom($specialty)?->value;
    }

    public function back(): void
    {
        if ($this->specialty !== null) {
            $this->specialty = null;
        } else {
            $this->city = null;
        }
    }

    public function setPeriod(string $period): void
    {
        $this->period = in_array($period, self::PERIODS, true) ? $period : 'month';
    }

    public function metricCase(): ?OverviewMetric
    {
        return OverviewMetric::tryFrom((string) $this->metric);
    }

    public function focusCase(): ?Specialty
    {
        return Specialty::tryFrom((string) $this->focus);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function bounds(): array
    {
        $tz = config('orthotrack.timezone');
        $now = now($tz);

        return match ($this->period) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfDay()],
            'all' => [Carbon::create(2000, 1, 1, 0, 0, 0, $tz), $now->copy()->endOfDay()],
            'custom' => $this->customBounds($now),
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = $this->user();
        $service = app(OverviewService::class);
        $metric = $this->metricCase();
        $focus = $this->focusCase();
        $city = Governorate::tryFrom((string) $this->city);
        $specialty = Specialty::tryFrom((string) $this->specialty);
        [$from, $until] = $this->bounds();

        // Only the governorates this company works in (Al-Fadhaa' Bureau: Baghdad, Karbala, Babil).
        $covered = CompanyContext::governorates();
        $governorates = collect([Governorate::KURDISTAN, Governorate::IRAQ])
            ->mapWithKeys(fn (string $region) => [$region => array_values(array_filter(Governorate::inRegion($region), fn (Governorate $g) => in_array($g, $covered, true)))])
            ->filter()->all();
        $regions = array_values(array_filter([Governorate::IRAQ, Governorate::KURDISTAN], fn (string $r) => isset($governorates[$r])));
        // "Total in Iraq and Kurdistan" only when the company works in both.
        $regions = count($regions) > 1 ? [...$regions, null] : $regions;

        $metrics = $metric !== null ? [$metric] : $service->metrics($user);
        $facts = [];
        $stations = [];

        foreach ($metrics as $m) {
            $facts[$m->value] = $service->facts($user, $m, $from, $until);
            $stations[$m->value] = $service->stations($user, $m);
        }

        return [
            // Not "metric" / "city"…: those names are the page's own (string) properties in the view.
            'theMetric' => $metric,
            'theFocus' => $focus,
            'theCity' => $city,
            'theSpecialty' => $specialty,
            'metrics' => $metrics,
            'usesPeriod' => $focus !== null || $metric?->usesPeriod(),
            'periodLabel' => Display::date($from).' → '.Display::date($until),
            'regions' => $regions,
            'governorates' => $governorates,
            'value' => fn (OverviewMetric $m, ?string $region = null, ?Governorate $g = null, ?Specialty $s = null) => $this->value($m, $facts[$m->value], $stations[$m->value], $region, $g, $s),
            'has' => fn (OverviewMetric $m, ?Governorate $g, ?Specialty $s = null) => $this->has($m, $facts[$m->value], $stations[$m->value], $g, $s),
            'unassigned' => collect($stations)->flatten(1)->unique('id')->filter(fn (Branch $b) => $b->governorate === null)->values(),
            'rows' => $city !== null && ($specialty ?? $focus) !== null ? $this->rows($metrics, $facts, $city, $specialty ?? $focus) : [],
            'columns' => $metrics,
        ];
    }

    /**
     * The number shown for a button: a count of pieces or stations, or USD.
     *
     * @param  Collection<int, OverviewFact>  $facts
     * @param  Collection<int, Branch>  $stations
     */
    private function value(OverviewMetric $metric, Collection $facts, Collection $stations, ?string $region, ?Governorate $governorate, ?Specialty $specialty): string
    {
        $service = app(OverviewService::class);

        // Stations without a specialty: the stations there, with or without stock.
        if ($metric === OverviewMetric::Stations && $specialty === null) {
            $count = $stations->filter(fn (Branch $b) => $b->governorate !== null
                && ($region === null || $b->governorate->region() === $region)
                && ($governorate === null || $b->governorate === $governorate))->count();

            return Display::number($count);
        }

        $total = $service->total($metric, $service->filter($facts, $region, $governorate, $specialty));

        return $metric->isMoney() ? Display::money($total, OverviewService::CURRENCY) : Display::number($total);
    }

    /**
     * Green or red: is there anything for this button in the governorate?
     *
     * @param  Collection<int, OverviewFact>  $facts
     * @param  Collection<int, Branch>  $stations
     */
    private function has(OverviewMetric $metric, Collection $facts, Collection $stations, ?Governorate $governorate, ?Specialty $specialty): bool
    {
        if ($metric === OverviewMetric::Stations && $specialty === null) {
            return $stations->contains(fn (Branch $b) => $b->governorate === $governorate);
        }

        return app(OverviewService::class)->filter($facts, governorate: $governorate, specialty: $specialty)->contains(fn (OverviewFact $f) => $f->qty > 0);
    }

    /**
     * Product by product (station × product) in one governorate and specialty, one column per
     * button; the biggest first.
     *
     * @param  list<OverviewMetric>  $metrics
     * @param  array<string, Collection<int, OverviewFact>>  $facts
     * @return list<array{sku: string, product: string, station: string, cells: array<string, string>}>
     */
    private function rows(array $metrics, array $facts, Governorate $city, Specialty $specialty): array
    {
        $service = app(OverviewService::class);
        $rows = [];

        foreach ($metrics as $metric) {
            foreach ($service->filter($facts[$metric->value], governorate: $city, specialty: $specialty) as $fact) {
                $key = $fact->branchId.'-'.$fact->productId;
                $rows[$key] ??= ['branch_id' => $fact->branchId, 'product_id' => $fact->productId, 'sort' => 0.0, 'cells' => []];
                $rows[$key]['cells'][$metric->value] = match (true) {
                    $metric === OverviewMetric::Stations, $metric === OverviewMetric::Stock => Display::number($fact->qty),
                    $metric === OverviewMetric::Sales => Display::money($fact->amount, OverviewService::CURRENCY).' ('.Display::number($fact->qty).')',
                    default => Display::money($fact->amount, OverviewService::CURRENCY),
                };
                $rows[$key]['sort'] = max($rows[$key]['sort'], $metric->isMoney() ? (float) $fact->amount : (float) $fact->qty);
            }
        }

        $products = Product::query()->whereIn('id', array_column($rows, 'product_id'))->get()->keyBy('id');
        $branches = Branch::query()->whereIn('id', array_column($rows, 'branch_id'))->get()->keyBy('id');

        usort($rows, fn ($a, $b) => $b['sort'] <=> $a['sort']);

        return array_map(fn (array $row) => [
            'sku' => (string) ($products[$row['product_id']]->sku ?? '—'),
            'product' => (string) ($products[$row['product_id']]->name ?? '—'),
            'station' => $branches->has($row['branch_id']) ? $branches[$row['branch_id']]->code.' · '.$branches[$row['branch_id']]->name : '—',
            'cells' => $row['cells'],
        ], $rows);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function customBounds(Carbon $now): array
    {
        $tz = config('orthotrack.timezone');

        try {
            $from = filled($this->from) ? Carbon::parse((string) $this->from, $tz)->startOfDay() : $now->copy()->startOfMonth();
            $until = filled($this->until) ? Carbon::parse((string) $this->until, $tz)->endOfDay() : $now->copy()->endOfDay();
        } catch (\Throwable) {
            return [$now->copy()->startOfMonth(), $now->copy()->endOfDay()];
        }

        return $until->lessThan($from) ? [$until->copy()->startOfDay(), $from->copy()->endOfDay()] : [$from, $until];
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
