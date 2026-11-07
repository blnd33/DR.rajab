<?php

namespace App\Support;

use App\Models\StockBatch;
use App\Models\StockUnit;
use App\Models\User;
use App\Services\Barcode\ScanResult;
use App\Services\Barcode\ScanResultKind;
use App\Services\Stock\FefoPicker;

/**
 * A scan result as plain data for the ops Scan screen (Livewire state must be serialisable).
 * Only shows what the user may see: units in other branches show the branch, not details.
 */
final class ScanCard
{
    /**
     * @return array<string, mixed>
     */
    public static function from(ScanResult $result, User $user, ?int $branchId, string $source): array
    {
        $card = [
            'id' => uniqid('scan', true),
            'kind' => $result->kind->value,
            'raw' => mb_substr($result->parsed->raw, 0, 200),
            'source' => $source,
            'at' => now()->toIso8601String(),
            'gs1' => $result->parsed->isGs1(),
            'warnings' => $result->warnings,
            'product' => null,
            'unit' => null,
            'lots' => [],
            'units' => [],
            'fefo' => null,
            'here' => true,
        ];

        if ($result->product !== null) {
            $card['product'] = [
                'id' => $result->product->id,
                'sku' => $result->product->sku,
                'name' => $result->product->name,
                'size' => $result->product->size,
                'serial_tracked' => $result->product->isSerialTracked(),
                'url' => $user->can('products.view_any') || $user->can('stock.view') ? route('ops.stock.product', $result->product) : null,
            ];
        }

        if ($result->kind === ScanResultKind::Unit && $result->unit !== null) {
            $unit = $result->unit;
            $canSee = $user->canInBranch('stock.view', $unit->branch_id);
            $card['here'] = $branchId === null || $unit->branch_id === $branchId;
            $card['unit'] = [
                'serial' => $unit->serial,
                'lot' => $canSee ? $unit->lot : null,
                'expiry' => $canSee ? $unit->expiry_date?->toDateString() : null,
                'status' => $unit->status->value,
                'status_label' => $unit->status->getLabel(),
                'status_color' => $unit->status->getColor(),
                'status_icon' => $unit->status->getIcon(),
                'branch' => $unit->branch->code,
                'where' => $canSee ? $unit->locationLabel() : null,
                'blocked' => $unit->isBlockedByExpiry(),
                'url' => $canSee ? route('ops.stock.unit', $unit) : null,
            ];
        }

        if (in_array($result->kind, [ScanResultKind::Lot, ScanResultKind::LotSearch], true)) {
            $card['lots'] = $result->batches
                ->filter(fn (StockBatch $b) => $user->canInBranch('stock.view', $b->branch_id))
                ->take(8)
                ->map(fn (StockBatch $b) => [
                    'sku' => $b->product?->sku,
                    'lot' => $b->lot,
                    'code' => $b->codeLabel(),
                    'expiry' => $b->expiry_date?->toDateString(),
                    'quantity' => $b->quantity,
                    'branch' => $b->branch->code,
                    'here' => $branchId === null || $b->branch_id === $branchId,
                ])->values()->all();
        }

        if ($result->kind === ScanResultKind::LotSearch) {
            $card['units'] = $result->units
                ->filter(fn (StockUnit $u) => $user->canInBranch('stock.view', $u->branch_id))
                ->take(8)
                ->map(fn (StockUnit $u) => [
                    'serial' => $u->serial,
                    'sku' => $u->product->sku,
                    'status_label' => $u->status->getLabel(),
                    'branch' => $u->branch->code,
                    'url' => route('ops.stock.unit', $u),
                ])->values()->all();
        }

        // What to use first in this branch (FEFO), for product / lot scans.
        if ($result->product !== null && $branchId !== null && in_array($result->kind, [ScanResultKind::Product, ScanResultKind::Lot, ScanResultKind::NewUnit], true)) {
            $next = app(FefoPicker::class)->suggest($result->product, $branchId);

            if ($next !== null) {
                $card['fefo'] = [
                    'code' => $next instanceof StockUnit ? $next->serial : ($next->lot ?? '—'),
                    'expiry' => $next->expiry_date?->toDateString(),
                    'where' => $next->locationLabel(),
                ];
            }
        }

        return $card;
    }

    /**
     * Was the scan "good" (success beep) or not (error buzz)?
     *
     * @param  array<string, mixed>  $card
     */
    public static function isGood(array $card): bool
    {
        return $card['kind'] !== ScanResultKind::Unknown->value
            && ($card['unit'] === null || ($card['here'] && ! $card['unit']['blocked']));
    }
}
