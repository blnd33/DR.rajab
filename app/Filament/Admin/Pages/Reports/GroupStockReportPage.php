<?php

namespace App\Filament\Admin\Pages\Reports;

use App\Services\Reports\GroupStockReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class GroupStockReportPage extends ReportPage
{
    protected static string $reportClass = GroupStockReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static bool $inStockMenu = true;

    protected static ?int $navigationSort = 30;
}
