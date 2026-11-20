<?php

namespace App\Filament\Admin\Pages\Reports;

use App\Services\Reports\PriceListReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class PriceListReportPage extends ReportPage
{
    protected static string $reportClass = PriceListReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return __('Catalogues & Pricelist');
    }
}
