<?php

namespace App\Services\Reports;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A report (P5-1): filters → rows → totals. The same rows feed the screen, Excel and print.
 * Money is returned in the context's display currency, converted with each row's snapshot.
 */
abstract class Report
{
    abstract public static function key(): string;

    abstract public function title(): string;

    public function description(): ?string
    {
        return null;
    }

    /**
     * Which filters the screen offers: period, branch, currency, group, idle ("Not used for").
     *
     * @return list<string>
     */
    public function filters(): array
    {
        return ['period', 'branch', 'currency'];
    }

    /**
     * Grouping choices (first = default).
     *
     * @return array<string, string>
     */
    public function groups(): array
    {
        return [];
    }

    public function defaultFrom(): Carbon
    {
        return now(config('orthotrack.timezone'))->startOfMonth();
    }

    /**
     * Profit reports need `reports.profit` to open at all.
     */
    public function needsProfit(): bool
    {
        return false;
    }

    /**
     * @return list<ReportColumn>
     */
    abstract public function columns(ReportContext $context): array;

    /**
     * @return list<array<string, mixed>> every row has a unique 'key'
     */
    abstract public function rows(ReportContext $context): array;

    /**
     * Columns the user may see (profit columns need reports.profit).
     *
     * @return list<ReportColumn>
     */
    public function visibleColumns(ReportContext $context): array
    {
        return array_values(array_filter($this->columns($context), fn (ReportColumn $c) => ! $c->profit || $context->canSeeProfit()));
    }

    /**
     * Sum of every total-able column.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, string>
     */
    public function totals(ReportContext $context, array $rows): array
    {
        $totals = [];

        foreach ($this->visibleColumns($context) as $column) {
            if (! $column->total) {
                continue;
            }

            $sum = BigDecimal::zero();

            foreach ($rows as $row) {
                if (is_numeric($row[$column->name] ?? null)) {
                    $sum = $sum->plus((string) $row[$column->name]);
                }
            }

            $totals[$column->name] = (string) $sum->toScale($column->decimals, RoundingMode::HalfUp);
        }

        return $totals;
    }

    /**
     * Display names (translated) of the given ids.
     *
     * @param  class-string<Model>  $model
     * @param  iterable<mixed>  $ids
     * @return array<int, string>
     */
    protected static function names(string $model, iterable $ids, string $attribute = 'name'): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', is_array($ids) ? $ids : iterator_to_array($ids)))));

        if ($ids === []) {
            return [];
        }

        return $model::query()->whereIn('id', $ids)->get()
            ->mapWithKeys(fn ($m) => [(int) $m->getKey() => (string) $m->getAttribute($attribute)])
            ->all();
    }

    protected static function money(BigDecimal $value): string
    {
        return (string) $value->toScale(2, RoundingMode::HalfUp);
    }

    protected static function percent(BigDecimal $part, BigDecimal $whole): ?string
    {
        return $whole->isZero() ? null : (string) $part->multipliedBy(100)->dividedBy($whole, 1, RoundingMode::HalfUp);
    }
}
