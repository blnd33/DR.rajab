<?php

namespace App\Services\Stock\Data;

use App\Models\StockBatch;
use App\Models\StockUnit;

final class ReceiveResult
{
    /**
     * @param  list<StockUnit>  $units
     * @param  list<StockBatch>  $batches  buckets that received quantity (one per line)
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly string $movementGroup,
        public readonly array $units,
        public readonly array $batches,
        public readonly int $quantity,
        public readonly array $warnings = [],
    ) {}
}
