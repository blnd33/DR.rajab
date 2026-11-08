<?php

namespace App\Services\Graphs;

/**
 * The numbers of one graph: labels on one axis, one or more series of values.
 */
final class GraphData
{
    /**
     * @param  list<string>  $labels
     * @param  list<array{label: string, values: list<float>}>  $series
     * @param  ?string  $currency  set when the values are money
     * @param  list<bool>  $other  true for the folded "Other" label
     */
    public function __construct(
        public readonly array $labels,
        public readonly array $series,
        public readonly ?string $currency = null,
        public readonly array $other = [],
    ) {}

    public function isEmpty(): bool
    {
        foreach ($this->series as $series) {
            foreach ($series['values'] as $value) {
                if ($value != 0) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return list<float>
     */
    public function values(int $series = 0): array
    {
        return $this->series[$series]['values'] ?? [];
    }

    public function total(int $series = 0): float
    {
        return array_sum($this->values($series));
    }
}
