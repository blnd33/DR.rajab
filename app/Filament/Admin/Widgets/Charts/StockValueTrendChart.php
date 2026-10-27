<?php

namespace App\Filament\Admin\Widgets\Charts;

use App\Models\StockValueSnapshot;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;
use App\Services\Reports\SnapshotConverters;
use App\Support\Locale\Display;
use Brick\Math\BigDecimal;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Auth;

/**
 * Stock value at true cost over the last 90 nights, in USD (P5-4 / P5-5).
 */
class StockValueTrendChart extends ChartWidget
{
    protected static ?int $sort = 8;

    protected ?string $maxHeight = '260px';

    protected int|string|array $columnSpan = ['default' => 1, 'md' => 2, 'xl' => 2];

    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('dashboard.view') && app(BranchScopeResolver::class)->hasAny($user, 'stock.view_cost');
    }

    public function getHeading(): string
    {
        return __('Stock value, last 90 days (USD)');
    }

    public function getDescription(): ?string
    {
        return __('One point per night (after 23:50).');
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        /** @var User $user */
        $user = Auth::user();
        $branchIds = app(BranchScopeResolver::class)->branchIdsFor($user, 'stock.view_cost');
        $fx = app(SnapshotConverters::class);
        $days = [];

        StockValueSnapshot::query()
            ->whereDate('snapshot_date', '>=', now(config('orthotrack.timezone'))->subDays(89)->toDateString())
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->orderBy('snapshot_date')
            ->get()
            ->each(function (StockValueSnapshot $s) use (&$days, $fx) {
                $day = $s->snapshot_date->toDateString();
                $usd = $fx->convert($s->value, $s->currency, 'USD', $s->fx_snapshot_id) ?? BigDecimal::zero();
                $days[$day] = BigDecimal::of($days[$day] ?? '0')->plus($usd);
            });

        return [
            'datasets' => [ChartStyle::line(__('Stock value'), array_map(fn ($v) => (float) (string) $v, array_values($days)))],
            'labels' => array_map(fn ($d) => Display::digits($d), array_keys($days)),
        ];
    }

    protected function getOptions(): array
    {
        $options = ChartStyle::options(1);
        $options['scales']['y']['beginAtZero'] = false;

        return $options;
    }
}
