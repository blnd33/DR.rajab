<?php

namespace App\Filament\Admin\Pages\Reports;

use App\Services\Reports\SurgeriesReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class SurgeriesReportPage extends ReportPage
{
    protected static string $reportClass = SurgeriesReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static ?int $navigationSort = 50;
}
