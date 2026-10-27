<?php

namespace App\Filament\Admin\Pages\Reports;

use App\Services\Reports\SalesProfitReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class SalesProfitReportPage extends ReportPage
{
    protected static string $reportClass = SalesProfitReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    protected static ?int $navigationSort = 40;
}
