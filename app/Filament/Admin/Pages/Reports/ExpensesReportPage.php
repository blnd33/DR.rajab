<?php

namespace App\Filament\Admin\Pages\Reports;

use App\Services\Reports\ExpensesReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class ExpensesReportPage extends ReportPage
{
    protected static string $reportClass = ExpensesReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?int $navigationSort = 80;
}
