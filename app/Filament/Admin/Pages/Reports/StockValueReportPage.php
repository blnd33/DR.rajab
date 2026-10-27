<?php

namespace App\Filament\Admin\Pages\Reports;

use App\Services\Reports\StockValueReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class StockValueReportPage extends ReportPage
{
    protected static string $reportClass = StockValueReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 10;
}
