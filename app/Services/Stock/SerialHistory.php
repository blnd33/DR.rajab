<?php

namespace App\Services\Stock;

use App\Models\StockMovement;
use App\Models\StockUnit;
use Illuminate\Support\Collection;

/**
 * Full timeline of one serial unit (Module 4): received (supplier), corrections, transfers,
 * kit placements, surgery / patient, sale, return — newest first. Every entry comes from the
 * append-only stock ledger; later phases add their references (transfer no., surgery…).
 */
class SerialHistory
{
    /**
     * @return Collection<int, StockMovement>
     */
    public function for(StockUnit $unit): Collection
    {
        return StockMovement::query()
            ->with(['user', 'fromBranch', 'toBranch', 'reference'])
            ->where('stock_unit_id', $unit->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * One-line human description of a movement for the timeline.
     */
    public function describe(StockMovement $movement): string
    {
        $from = $movement->fromBranch?->code;
        $to = $movement->toBranch?->code;

        return match (true) {
            $movement->from_status !== null && $movement->to_status !== null && $movement->from_status !== $movement->to_status => __(':from → :to', [
                'from' => $movement->from_status->getLabel(),
                'to' => $movement->to_status->getLabel(),
            ]),
            $from !== null && $to !== null && $from !== $to => __(':from → :to', ['from' => $from, 'to' => $to]),
            $to !== null && $from === null => __('Into :branch', ['branch' => $to]),
            default => '',
        };
    }
}
