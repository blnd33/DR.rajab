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

    public function icon(): string
    {
        return match ($this->kind) {
            self::DOUGHNUT => 'heroicon-o-chart-pie',
            self::LINE => 'heroicon-o-presentation-chart-line',
            default => 'heroicon-o-chart-bar',
        };
    }
}
