<?php

namespace App\Filament\Admin\Pages\Reports;

use App\Services\Reports\InventoryReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class InventoryReportPage extends ReportPage
{
    protected static string $reportClass = InventoryReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static bool $inStockMenu = true;

    protected static ?int $navigationSort = 10;
}
