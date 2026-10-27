<?php

namespace App\Filament\Admin\Widgets\Charts;

use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;
use App\Services\Reports\ReportContext;
use App\Services\Reports\SalesProfitReport;
use App\Support\Locale\Display;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Auth;

/**
 * Sales vs cost of what was sold, per month for a year, in USD (P5-5). Cost only with
 * reports.profit. Same numbers as the Sales and profit report.
 */
class SalesTrendChart extends ChartWidget
{
    protected static ?int $sort = 6;

    protected ?string $maxHeight = '260px';

    protected int|string|array $columnSpan = ['default' => 1, 'md' => 2, 'xl' => 2];

    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('dashboard.view') && app(BranchScopeResolver::class)->hasAny($user, 'reports.view');
    }

    public function getHeading(): string
    {
        return __('Sales per month (USD)');
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        /** @var User $user */
        $user = Auth::user();
        $months = ChartStyle::months();
        $report = app(SalesProfitReport::class);
        $context = ReportContext::make($report, $user, ['from' => $months[0].'-01', 'group' => 'month', 'currency' => 'USD']);
        $rows = collect($report->rows($context))->keyBy('month');

        $series = [ChartStyle::line(__('Revenue'), array_map(fn ($m) => (float) ($rows[$m]['revenue'] ?? 0), $months), 0)];

        if ($context->canSeeProfit()) {
            $series[] = ChartStyle::line(__('Cost'), array_map(fn ($m) => (float) ($rows[$m]['cost'] ?? 0), $months), 1);
        }

        return ['datasets' => $series, 'labels' => array_map(fn ($m) => Display::digits($m), $months)];
    }

    protected function getOptions(): array
    {
        return ChartStyle::options(Auth::user()?->can('reports.profit') ? 2 : 1);
    }
}
