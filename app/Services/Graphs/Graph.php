<?php

namespace App\Services\Graphs;

use Closure;

/**
 * One graph of the Graphs page: what it shows, how it is drawn and where its numbers come from.
 */
final class Graph
{
    public const DOUGHNUT = 'doughnut';

    /** Ranking: horizontal bars, top 10. */
    public const RANKING = 'ranking';

    /** Months: vertical bars per month. */
    public const MONTHS = 'months';

    /** Change over time: one or more lines. */
    public const LINE = 'line';

    /** Forms the user can switch between on the graph page. */
    public const VIEW_PIE = 'pie';

    public const VIEW_BARS = 'bars';

    public const VIEW_LINE = 'line';

    /**
     * @param  Closure(GraphContext): GraphData  $build
     * @param  list<string>  $keywords  extra English search words
     */
    public function __construct(
        public readonly string $key,
        public readonly GraphSection $section,
        public readonly string $title,
        public readonly string $description,
        public readonly string $kind,
        public readonly Closure $build,
        public readonly array $keywords = [],
        public readonly bool $needsProfit = false,
        public readonly bool $usesPeriod = true,
        public readonly bool $usesCurrency = false,
        // False for levels (stock value per night): no total, no share.
        public readonly bool $summable = true,
    ) {}

    public function kindLabel(): string
    {
        return match ($this->kind) {
            self::DOUGHNUT => __('Pie chart'),
            self::RANKING => __('Bar chart (ranking)'),
            self::MONTHS => __('Bar chart (per month)'),
            default => __('Line chart'),
        };
    }

    public function isTimeline(): bool
    {
        return in_array($this->kind, [self::MONTHS, self::LINE], true);
    }

    /**
     * How the graph opens: its own form.
     */
    public function defaultView(): string
    {
        return match ($this->kind) {
            self::DOUGHNUT => self::VIEW_PIE,
            self::LINE => self::VIEW_LINE,
            default => self::VIEW_BARS,
        };
    }

    /**
     * Forms the user may switch to: pie and bars for every graph, a line for graphs over time
     * (a line between unrelated names would suggest a trend that is not there).
     *
     * @return list<string>
     */
    public function views(): array
    {
        return $this->isTimeline() ? [self::VIEW_PIE, self::VIEW_BARS, self::VIEW_LINE] : [self::VIEW_PIE, self::VIEW_BARS];
    }

    /**
     * The drawn form for a chosen view: bars run across for months and along for names.
     */
    public function kindFor(string $view): string
    {
        return match ($view) {
            self::VIEW_PIE => self::DOUGHNUT,
            self::VIEW_LINE => self::LINE,
            default => $this->isTimeline() ? self::MONTHS : self::RANKING,
        };
    }

    public static function viewLabel(string $view): string
    {
        return match ($view) {
            self::VIEW_PIE => __('Pie'),
            self::VIEW_LINE => __('Line'),
            default => __('Bars'),
        };
    }

    public static function viewIcon(string $view): string
    {
        return match ($view) {
            self::VIEW_PIE => 'heroicon-m-chart-pie',
            self::VIEW_LINE => 'heroicon-m-presentation-chart-line',
            default => 'heroicon-m-chart-bar',
        };
    }

    public function icon(): string
    {
        return match ($this->kind) {
            self::DOUGHNUT => 'heroicon-o-chart-pie',
            self::LINE => 'heroicon-o-presentation-chart-line',
            default => 'heroicon-o-chart-bar',
        };
    }
}
