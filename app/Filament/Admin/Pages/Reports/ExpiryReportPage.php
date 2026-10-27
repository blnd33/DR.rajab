<?php

namespace App\Filament\Admin\Pages\Reports;

use App\Services\Reports\ExpiryReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class ExpiryReportPage extends ReportPage
{
    protected static string $reportClass = ExpiryReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?int $navigationSort = 20;
}
