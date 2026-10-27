<?php

namespace App\Filament\Admin\Pages\Reports;

use App\Services\Reports\CashReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class CashReportPage extends ReportPage
{
    protected static string $reportClass = CashReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static ?int $navigationSort = 70;
}
