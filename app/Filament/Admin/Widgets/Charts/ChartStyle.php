<?php

namespace App\Filament\Admin\Widgets\Charts;

/**
 * Shared look of the dashboard charts (P5-5). Series colours are fixed by slot and were run
 * through the palette validator on the light (#ffffff) and dark (#18181b) panel surfaces:
 * lightness band, chroma, colour-blind and normal-vision separation, contrast — all pass.
 */
final class ChartStyle
{
    /** Slot 1 (blue), slot 2 (orange): assigned in this order, never cycled. */
    public const SERIES = ['#2a78d6', '#d95926'];

    /**
     * One line series: 2px line, 8px markers, no fill.
     *
     * @param  list<float>  $data
     * @return array<string, mixed>
     */
    public static function line(string $label, array $data, int $slot = 0): array
    {
        return [
            'label' => $label,
            'data' => $data,
            'borderColor' => self::SERIES[$slot],
            'backgroundColor' => self::SERIES[$slot],
            'borderWidth' => 2,
            'pointRadius' => 4,
            'pointHoverRadius' => 6,
            'pointHitRadius' => 12,
            'tension' => 0.25,
            'fill' => false,
        ];
    }

    /**
     * One bar series: rounded data ends, 2px gap between bars.
     *
     * @param  list<float>  $data
     * @return array<string, mixed>
     */
    public static function bar(string $label, array $data, int $slot = 0): array
    {
        return [
            'label' => $label,
            'data' => $data,
            'backgroundColor' => self::SERIES[$slot],
            'borderRadius' => 4,
            'borderSkipped' => 'start',
            'categoryPercentage' => 0.8,
            'barPercentage' => 0.9,
        ];
    }

    /**
     * Recessive grid and axes; a legend only for two or more series.
     *
     * @return array<string, mixed>
     */
    public static function options(int $series): array
    {
        return [
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'plugins' => [
                'legend' => ['display' => $series > 1, 'position' => 'bottom', 'labels' => ['usePointStyle' => true, 'boxWidth' => 8]],
                'tooltip' => ['enabled' => true],
            ],
            'scales' => [
                'x' => ['grid' => ['display' => false]],
                'y' => ['beginAtZero' => true, 'grid' => ['color' => 'rgba(148, 163, 184, 0.18)'], 'border' => ['display' => false]],
            ],
        ];
    }

    /**
     * The last twelve months as Y-m keys, oldest first.
     *
     * @return list<string>
     */
    public static function months(int $count = 12): array
    {
        $start = now(config('orthotrack.timezone'))->startOfMonth()->subMonthsNoOverflow($count - 1);

        return array_map(fn (int $i) => $start->copy()->addMonthsNoOverflow($i)->format('Y-m'), range(0, $count - 1));
    }
}
