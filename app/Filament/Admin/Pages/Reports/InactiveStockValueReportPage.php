<?php

namespace App\Filament\Admin\Pages\Reports;

use App\Services\Reports\InactiveStockValueReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/**
 * Its own section of the side menu, under Stock.
 */
class InactiveStockValueReportPage extends ReportPage
{
    protected static string $reportClass = InactiveStockValueReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return __('Inactive stock value');
    }
}
