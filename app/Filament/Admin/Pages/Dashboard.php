<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Widgets\Charts\SalesTrendChart;
use App\Filament\Admin\Widgets\Charts\StockValueTrendChart;
use App\Filament\Admin\Widgets\Charts\SurgeriesTrendChart;
use App\Filament\Admin\Widgets\ClinicalOverview;
use App\Filament\Admin\Widgets\ExpiryByBranch;
use App\Filament\Admin\Widgets\InvoicesOverview;
use App\Filament\Admin\Widgets\MoneyOverview;
use App\Filament\Admin\Widgets\MorningSummaryCard;
use App\Filament\Admin\Widgets\OverviewLauncher;
use App\Filament\Admin\Widgets\PendingWork;
use App\Filament\Admin\Widgets\StockOverview;
use App\Filament\Admin\Widgets\SystemAlerts;
use App\Filament\Admin\Widgets\SystemOverview;
use App\Filament\Admin\Widgets\TopImplants;
use App\Filament\Admin\Widgets\UnpaidInvoices;
use App\Support\AdminCompany;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Admin dashboard (Module 26): the big specialty / overview buttons and the search, system alerts, the morning summary line, status, stock, money,
 * invoices paid / not paid and who owes us,
 * waiting work, clinical,
 * expiry per branch, then the trends (sales, surgeries, stock value) and the most used implants.
 * Each widget shows only what the user may see.
 * Another company (top bar, e.g. Al-Fadhaa' Bureau) gets the same page for its own stations —
 * none yet, so zero — without the system-wide parts (alerts, users, daily report).
 */
class Dashboard extends BaseDashboard
{
    public function getTitle(): string
    {
        $company = AdminCompany::current();

        return $company->isMain() ? __('Main page') : __('Main page').' — '.$company->getLabel();
    }

    public function getSubheading(): ?string
    {
        return AdminCompany::current()->isMain() ? null : __('This company has no stations yet, so everything shows zero.');
    }

    public static function getNavigationLabel(): string
    {
        return __('Main page');
    }

    public function getWidgets(): array
    {
        $widgets = [
            OverviewLauncher::class,
            SystemAlerts::class,
            MorningSummaryCard::class,
            SystemOverview::class,
            StockOverview::class,
            MoneyOverview::class,
            InvoicesOverview::class,
            UnpaidInvoices::class,
            PendingWork::class,
            ClinicalOverview::class,
            ExpiryByBranch::class,
            SalesTrendChart::class,
            SurgeriesTrendChart::class,
            StockValueTrendChart::class,
            TopImplants::class,
        ];

        if (AdminCompany::current()->isMain()) {
            return $widgets;
        }

        return array_values(array_diff($widgets, [SystemAlerts::class, MorningSummaryCard::class, SystemOverview::class]));
    }

    public function getColumns(): int|array
    {
        return ['default' => 1, 'md' => 2, 'xl' => 4];
    }
}
