<?php

namespace App\Services\Reports;

use App\Exports\ArraySheet;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;
use App\Support\Locale\Display;
use Illuminate\Support\Str;

/**
 * Every report by its key, who may open it, and its Excel sheet.
 */
final class ReportRegistry
{
    /** @var list<class-string<Report>> */
    public const REPORTS = [
        StockValueReport::class,
        ExpiryReport::class,
        StockMovementsReport::class,
        SalesProfitReport::class,
        InvoicesReport::class,
        SurgeriesReport::class,
        SupplierAgingReport::class,
        CashReport::class,
        ExpensesReport::class,
        KitsReport::class,
    ];

    public static function find(string $key): ?Report
    {
        foreach (self::REPORTS as $class) {
            if ($class::key() === $key) {
                return app($class);
            }
        }

        return null;
    }

    public static function canOpen(User $user, Report $report): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'reports.view')
            && (! $report->needsProfit() || $user->can('reports.profit'));
    }

    public static function canExport(User $user): bool
    {
        return $user->can('reports.export');
    }

    /**
     * The rows as shown on screen (numbers as numbers, money rounded), with a totals line.
     */
    public static function sheet(Report $report, ReportContext $context): ArraySheet
    {
        $columns = $report->visibleColumns($context);
        $rows = $report->rows($context);
        $totals = $report->totals($context, $rows);
        $headings = array_map(fn (ReportColumn $c) => $c->type === ReportColumn::MONEY && $c->currencyKey === null ? "{$c->label} ({$context->currency})" : $c->label, $columns);

        $data = array_map(fn (array $row) => array_map(fn (ReportColumn $c) => self::cell($c, $row), $columns), $rows);

        if ($totals !== []) {
            $data[] = array_map(fn (ReportColumn $c, int $i) => $i === 0 ? __('Total') : (isset($totals[$c->name]) ? (float) $totals[$c->name] : ''), $columns, array_keys($columns));
        }

        $textColumns = [];

        foreach ($columns as $i => $column) {
            if (in_array($column->type, [ReportColumn::CODE, ReportColumn::DATE], true)) {
                $textColumns[] = $i + 1;
            }
        }

        return new ArraySheet($report->title(), $headings, $data, $textColumns, app()->getLocale() !== 'en');
    }

    public static function filename(Report $report, ReportContext $context): string
    {
        return Str::slug($report::key()).'-'.$context->from->toDateString().'-'.$context->until->toDateString().'.xlsx';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function cell(ReportColumn $column, array $row): mixed
    {
        $value = $row[$column->name] ?? null;

        return match (true) {
            $value === null => '',
            $column->isNumeric() => (float) $value,
            default => (string) $value,
        };
    }

    /**
     * Screen / paper formatting of one value.
     *
     * @param  array<string, mixed>  $row
     */
    public static function display(ReportColumn $column, array $row, ReportContext $context): string
    {
        $value = $row[$column->name] ?? null;

        if ($value === null || $value === '') {
            return '—';
        }

        return match ($column->type) {
            ReportColumn::MONEY => Display::money((string) $value, $column->currencyKey !== null ? (string) ($row[$column->currencyKey] ?? $context->currency) : $context->currency),
            ReportColumn::NUMBER => Display::number((string) $value),
            ReportColumn::PERCENT => Display::number((string) $value, 1).'%',
            ReportColumn::DATE => Display::date((string) $value),
            default => (string) $value,
        };
    }
}
