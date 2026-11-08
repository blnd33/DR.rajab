<?php

namespace App\Filament\Admin\Graphs;

use App\Services\Graphs\Graph;
use Filament\Widgets\ChartWidget;

/**
 * Draws one graph of the Graphs page. The page computes the numbers once (for the chart and
 * its table) and hands them over. Colours come from the theme-aware palette plugin
 * (resources/js/admin/chart-palette.js): pies get one slot per slice (-1 = "Other", grey),
 * bars and lines one slot per series — fixed order, never cycled.
 */
class GraphChart extends ChartWidget
{
    public string $kind = Graph::DOUGHNUT;

    /** @var array{labels: list<string>, series: list<array{label: string, values: list<float>}>, other: list<bool>, money?: bool} */
    public array $payload = ['labels' => [], 'series' => [], 'other' => []];

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '420px';

    protected int|string|array $columnSpan = 'full';

    protected function getType(): string
    {
        return match ($this->kind) {
            Graph::DOUGHNUT => 'doughnut',
            Graph::LINE => 'line',
            default => 'bar',
        };
    }

    protected function getData(): array
    {
        $datasets = [];

        foreach ($this->payload['series'] as $index => $series) {
            $dataset = ['label' => $series['label'], 'data' => $series['values']];

            if ($this->kind === Graph::DOUGHNUT) {
                $dataset['otSlots'] = array_map(fn (int $i) => ($this->payload['other'][$i] ?? false) ? -1 : $i, array_keys($series['values']));
                $dataset['spacing'] = 2;
            } else {
                $dataset['otSlot'] = $index;
                $dataset += $this->kind === Graph::LINE
                    ? ['borderWidth' => 2, 'pointRadius' => 4, 'pointHoverRadius' => 6, 'pointHitRadius' => 12, 'tension' => 0.25, 'fill' => false]
                    : ['borderRadius' => 4, 'borderSkipped' => 'start', 'borderWidth' => 0, 'categoryPercentage' => 0.8, 'barPercentage' => 0.9];
            }

            $datasets[] = $dataset;
        }

        return ['datasets' => $datasets, 'labels' => $this->payload['labels']];
    }

    protected function getOptions(): array
    {
        $multi = count($this->payload['series']) > 1;

        if ($this->kind === Graph::DOUGHNUT) {
            return [
                'cutout' => '55%',
                'plugins' => [
                    'legend' => ['display' => true, 'position' => 'bottom', 'labels' => ['usePointStyle' => true, 'boxWidth' => 8]],
                    'tooltip' => ['enabled' => true],
                ],
                'scales' => ['x' => ['display' => false], 'y' => ['display' => false]],
            ];
        }

        $value = ['beginAtZero' => true, 'grid' => ['color' => 'rgba(148, 163, 184, 0.18)'], 'border' => ['display' => false]];

        if (! ($this->payload['money'] ?? false)) {
            $value['ticks'] = ['precision' => 0]; // counts: whole numbers on the axis
        }
        $category = ['grid' => ['display' => false]];

        return [
            'indexAxis' => $this->kind === Graph::RANKING ? 'y' : 'x',
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'plugins' => [
                'legend' => ['display' => $multi, 'position' => 'bottom', 'labels' => ['usePointStyle' => true, 'boxWidth' => 8]],
                'tooltip' => ['enabled' => true],
            ],
            'scales' => $this->kind === Graph::RANKING ? ['x' => $value, 'y' => $category] : ['x' => $category, 'y' => $value],
        ];
    }
}
