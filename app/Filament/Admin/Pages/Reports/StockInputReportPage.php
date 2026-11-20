<?php

namespace App\Filament\Admin\Pages\Reports;

use App\Services\Reports\StockInputReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class StockInputReportPage extends ReportPage
{
    protected static string $reportClass = StockInputReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static bool $inStockMenu = true;

    protected static ?int $navigationSort = 60;
}
