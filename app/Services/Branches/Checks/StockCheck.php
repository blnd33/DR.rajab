<?php

namespace App\Services\Branches\Checks;

use App\Enums\StockStatus;
use App\Models\Branch;
use App\Models\BranchLocation;
use App\Models\BranchProductSetting;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\StockUnit;
use App\Services\Branches\Contracts\BranchDependencyCheck;

/**
 * A branch can only close with zero stock. Phase 2 adds the one-click
 * "Transfer all stock to branch X" action next to this blocker.
 */
class StockCheck implements BranchDependencyCheck
{
    public function closureBlocker(Branch $branch): ?string
    {
        $units = StockUnit::query()->where('branch_id', $branch->id)->whereIn('status', StockStatus::onHand())->count();
        $lotQuantity = (int) StockBatch::query()->where('branch_id', $branch->id)->where('quantity', '>', 0)->sum('quantity');

        if ($units === 0 && $lotQuantity === 0) {
            return null;
        }

        return __('Stock is still in this branch: :units serial units and :qty lot items. Move or write them off first.', [
            'units' => $units,
            'qty' => $lotQuantity,
        ]);
    }

    public function hasRecords(Branch $branch): bool
    {
        return StockUnit::query()->where('branch_id', $branch->id)->exists()
            || StockBatch::query()->where('branch_id', $branch->id)->exists()
            || StockMovement::query()->where(fn ($q) => $q->where('from_branch_id', $branch->id)->orWhere('to_branch_id', $branch->id))->exists()
            || BranchLocation::query()->where('branch_id', $branch->id)->exists()
            || BranchProductSetting::query()->where('branch_id', $branch->id)->exists();
    }
}
