<?php

namespace App\Filament\Admin\Pages\Reports;

use App\Services\Reports\StockValueByItemReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class StockValueByItemReportPage extends ReportPage
{
    protected static string $reportClass = StockValueByItemReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static bool $inStockMenu = true;

    protected static ?int $navigationSort = 40;
}
