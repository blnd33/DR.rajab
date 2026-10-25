<?php

namespace App\Services\Stock;

use App\Models\StockBatch;
use App\Models\StockUnit;
use App\Support\Database\Pg;
use Illuminate\Support\Collection;

/**
 * Recall by lot (Module 4): every unit and lot bucket with that lot number, wherever it is now —
 * on a shelf, in transit, in a kit, at a hospital, used in a surgery (Phase 3 shows the patient),
 * sold or returned. Searches all branches (stock.recall is a global permission).
 */
class RecallSearch
{
    public const LIMIT = 500;

    /**
     * @return array{units: Collection<int, StockUnit>, batches: Collection<int, StockBatch>, exact: bool}
     */
    public function search(string $lot, ?int $productId = null): array
    {
        $lot = trim($lot);

        if ($lot === '') {
            return ['units' => new Collection, 'batches' => new Collection, 'exact' => true];
        }

        $exact = $this->run($lot, $productId, true);

        if ($exact['units']->isNotEmpty() || $exact['batches']->isNotEmpty()) {
            return $exact + ['exact' => true];
        }

        // Nothing exact: partial match (typed part of a lot number).
        return $this->run($lot, $productId, false) + ['exact' => false];
    }

    /**
     * @return array{units: Collection<int, StockUnit>, batches: Collection<int, StockBatch>}
     */
    private function run(string $lot, ?int $productId, bool $exact): array
    {
        $operator = $exact ? '=' : (Pg::isPostgres() ? 'ilike' : 'like');
        $value = $exact ? $lot : '%'.str_replace(['%', '_'], ['\%', '\_'], $lot).'%';

        $units = StockUnit::query()
            ->with(['product', 'branch', 'location', 'supplier'])
            ->where('lot', $operator, $value)
            ->when($productId !== null, fn ($q) => $q->where('product_id', $productId))
            ->orderBy('product_id')
            ->orderBy('serial')
            ->limit(self::LIMIT)
            ->get();

        $batches = StockBatch::query()
            ->with(['product', 'branch', 'location', 'supplier'])
            ->where('lot', $operator, $value)
            ->when($productId !== null, fn ($q) => $q->where('product_id', $productId))
            ->orderByDesc('quantity')
            ->limit(self::LIMIT)
            ->get();

        return ['units' => $units, 'batches' => $batches];
    }
}
