<?php

namespace App\Filament\Admin\Pages\Reports;

use App\Services\Reports\StockMovementsReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class StockMovementsReportPage extends ReportPage
{
    protected static string $reportClass = StockMovementsReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static bool $inStockMenu = true;

    // Right after Stock → Stock movement (the list): the totals per type / station / item.
    protected static ?int $navigationSort = 55;

    public static function getNavigationLabel(): string
    {
        return __('Stock movement totals');
    }

    // Not in the menu (the client's list): Stock movement shows the movements themselves.
    protected static bool $shouldRegisterNavigation = false;
}
