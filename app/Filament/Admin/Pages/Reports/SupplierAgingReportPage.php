<?php

namespace App\Filament\Admin\Pages\Reports;

use App\Services\Reports\SupplierAgingReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class SupplierAgingReportPage extends ReportPage
{
    protected static string $reportClass = SupplierAgingReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?int $navigationSort = 60;
}
