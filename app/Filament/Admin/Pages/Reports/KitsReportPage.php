<?php

namespace App\Filament\Admin\Pages\Reports;

use App\Services\Reports\KitsReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class KitsReportPage extends ReportPage
{
    protected static string $reportClass = KitsReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?int $navigationSort = 90;
}
