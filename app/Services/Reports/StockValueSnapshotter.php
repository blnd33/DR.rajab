<?php

namespace App\Services\Reports;

use App\Models\StockValueSnapshot;
use App\Services\Money\FxService;
use App\Services\Stock\StockQuery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Nightly stock value per branch and cost currency (P5-4), plus its USD value at that day's
 * rates. Running it twice on the same day replaces that day's rows.
 */
class StockValueSnapshotter
{
    public function __construct(
        private readonly StockQuery $stock,
        private readonly FxService $fx,
    ) {}

    /**
     * @return int rows written
     */
    public function snapshot(?Carbon $day = null): int
    {
        $date = ($day ?? now(config('orthotrack.timezone')))->toDateString();
        $snapshot = $this->fx->current();
        $converter = $snapshot !== null ? $this->fx->converter($snapshot) : null;
        $rows = $this->stock->valueByBranchAndCurrency();

        return DB::transaction(function () use ($date, $snapshot, $converter, $rows) {
            StockValueSnapshot::query()->whereDate('snapshot_date', $date)->delete();
            $written = 0;

            foreach ($rows as $branchId => $currencies) {
                foreach ($currencies as $currency => $row) {
                    // A plain Y-m-d (the date cast would store a time on SQLite and break the unique day key).
                    StockValueSnapshot::query()->insert([
                        'snapshot_date' => $date,
                        'branch_id' => $branchId,
                        'currency' => $currency,
                        'value' => $row['value'],
                        'value_usd' => $converter?->toUsd($row['value'], $currency),
                        'units' => $row['units'],
                        'fx_snapshot_id' => $snapshot?->id,
                        'created_at' => now(),
                    ]);
                    $written++;
                }
            }

            return $written;
        });
    }
}
