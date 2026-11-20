<?php

namespace App\Filament\Admin\Pages\Reports;

use App\Services\Reports\PriceListReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class PriceListReportPage extends ReportPage
{
    protected static string $reportClass = PriceListReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static bool $inStockMenu = true;

    protected static ?int $navigationSort = 90;
}
