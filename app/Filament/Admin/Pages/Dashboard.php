<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Widgets\Charts\SalesTrendChart;
use App\Filament\Admin\Widgets\Charts\StockValueTrendChart;
use App\Filament\Admin\Widgets\Charts\SurgeriesTrendChart;
use App\Filament\Admin\Widgets\ClinicalOverview;
use App\Filament\Admin\Widgets\ExpiryByBranch;
use App\Filament\Admin\Widgets\MoneyOverview;
use App\Filament\Admin\Widgets\MorningSummaryCard;
use App\Filament\Admin\Widgets\PendingWork;
use App\Filament\Admin\Widgets\StockOverview;
use App\Filament\Admin\Widgets\SystemAlerts;
use App\Filament\Admin\Widgets\SystemOverview;
use App\Filament\Admin\Widgets\TopImplants;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Admin dashboard (Module 26): system alerts, the morning summary line, status, stock, money,
 * waiting work, clinical,
 * expiry per branch, then the trends (sales, surgeries, stock value) and the most used implants.
 * Each widget shows only what the user may see.
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
            SystemAlerts::class,
            MorningSummaryCard::class,
            SystemOverview::class,
            StockOverview::class,
            MoneyOverview::class,
            PendingWork::class,
            ClinicalOverview::class,
            ExpiryByBranch::class,
            SalesTrendChart::class,
            SurgeriesTrendChart::class,
            StockValueTrendChart::class,
            TopImplants::class,
        ];
    }

    public function getColumns(): int|array
    {
        return ['default' => 1, 'md' => 2, 'xl' => 4];
    }
}
