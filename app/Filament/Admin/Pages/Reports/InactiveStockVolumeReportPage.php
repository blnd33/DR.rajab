<?php

namespace App\Filament\Admin\Pages\Reports;

use App\Services\Reports\InactiveStockReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/**
 * Its own section of the side menu, under Stock.
 */
class InactiveStockVolumeReportPage extends ReportPage
{
    protected static string $reportClass = InactiveStockReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPauseCircle;

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return __('Inactive stock volume');
    }
}
