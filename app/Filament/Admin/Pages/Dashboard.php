<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Widgets\ClinicalOverview;
use App\Filament\Admin\Widgets\ExpiryByBranch;
use App\Filament\Admin\Widgets\PendingWork;
use App\Filament\Admin\Widgets\StockOverview;
use App\Filament\Admin\Widgets\SystemOverview;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Admin dashboard. Phase 0 shows system status; Phase 5 adds the full
 * Super Admin dashboard (surgeries, stock value, debts, approvals, alerts).
 */
class Dashboard extends BaseDashboard
{
    public function getTitle(): string
    {
        return __('Dashboard');
    }

    public static function getNavigationLabel(): string
    {
        return __('Dashboard');
    }

    public function getWidgets(): array
    {
        return [
            SystemOverview::class,
            StockOverview::class,
            PendingWork::class,
            ClinicalOverview::class,
            ExpiryByBranch::class,
        ];
    }

    public function getColumns(): int|array
    {
        return ['default' => 1, 'md' => 2, 'xl' => 4];
    }
}
