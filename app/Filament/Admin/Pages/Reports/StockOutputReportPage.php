<?php

namespace App\Filament\Admin\Pages\Reports;

use App\Services\Reports\StockOutputReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class StockOutputReportPage extends ReportPage
{
    protected static string $reportClass = StockOutputReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static bool $inStockMenu = true;

    protected static ?int $navigationSort = 70;
}
