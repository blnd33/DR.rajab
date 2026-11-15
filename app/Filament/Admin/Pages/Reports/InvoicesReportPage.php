<?php

namespace App\Filament\Admin\Pages\Reports;

use App\Services\Reports\InvoicesReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class InvoicesReportPage extends ReportPage
{
    protected static string $reportClass = InvoicesReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyDollar;

    protected static ?int $navigationSort = 45;
}
