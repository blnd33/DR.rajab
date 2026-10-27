<?php

namespace App\Filament\Admin\Widgets\Charts;

use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;
use App\Services\Reports\ReportContext;
use App\Services\Reports\SurgeriesReport;
use App\Support\Locale\Display;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Auth;

/**
 * Completed surgeries per month for a year (P5-5), only the surgeries the user may see.
 */
class SurgeriesTrendChart extends ChartWidget
{
    protected static ?int $sort = 7;

    protected ?string $maxHeight = '260px';

    protected int|string|array $columnSpan = ['default' => 1, 'md' => 2, 'xl' => 2];

    public static function canView(): bool
    {
        $user = Auth::user();
        $scope = app(BranchScopeResolver::class);

        return $user instanceof User && $user->can('dashboard.view') && $scope->hasAny($user, 'reports.view') && $scope->hasAny($user, 'surgeries.view_any');
    }

    public function getHeading(): string
    {
        return __('Surgeries per month');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        /** @var User $user */
        $user = Auth::user();
        $months = ChartStyle::months();
        $report = app(SurgeriesReport::class);
        $context = ReportContext::make($report, $user, ['from' => $months[0].'-01', 'group' => 'month']);
        $rows = collect($report->rows($context))->keyBy('label');

        return [
            'datasets' => [ChartStyle::bar(__('Surgeries'), array_map(fn ($m) => (float) ($rows[$m]['surgeries'] ?? 0), $months))],
            'labels' => array_map(fn ($m) => Display::digits($m), $months),
        ];
    }

    protected function getOptions(): array
    {
        $options = ChartStyle::options(1);
        $options['scales']['y']['ticks'] = ['precision' => 0];

        return $options;
    }
}
