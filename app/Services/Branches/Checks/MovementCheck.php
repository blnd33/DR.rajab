<?php

namespace App\Services\Branches\Checks;

use App\Enums\StockCountStatus;
use App\Enums\StockRequestStatus;
use App\Enums\TransferStatus;
use App\Enums\WriteOffStatus;
use App\Models\Branch;
use App\Models\CustomerReturn;
use App\Models\StockCount;
use App\Models\StockRequest;
use App\Models\SupplierReturn;
use App\Models\Transfer;
use App\Models\WriteOff;
use App\Services\Branches\Contracts\BranchDependencyCheck;

/**
 * Open transfers, stock requests, counts and write-offs must be finished before a branch
 * closes (Module 1). Any of these documents means the branch has history.
 */
class MovementCheck implements BranchDependencyCheck
{
    public function closureBlocker(Branch $branch): ?string
    {
        $transfers = Transfer::query()
            ->whereIn('status', [TransferStatus::Draft, TransferStatus::InTransit])
            ->where(fn ($q) => $q->where('from_branch_id', $branch->id)->orWhere('to_branch_id', $branch->id))
            ->count();

        $requests = StockRequest::query()
            ->where('status', StockRequestStatus::Pending)
            ->where(fn ($q) => $q->where('requesting_branch_id', $branch->id)->orWhere('preferred_source_branch_id', $branch->id))
            ->count();

        $counts = StockCount::query()->where('branch_id', $branch->id)
            ->whereIn('status', [StockCountStatus::InProgress, StockCountStatus::Submitted, StockCountStatus::Reviewed])->count();

        $writeOffs = WriteOff::query()->where('branch_id', $branch->id)->where('status', WriteOffStatus::Pending)->count();

        if ($transfers + $requests + $counts + $writeOffs === 0) {
            return null;
        }

        return __('Open documents: :transfers transfers, :requests stock requests, :counts stock counts, :writeoffs write-offs. Finish or cancel them first.', [
            'transfers' => $transfers,
            'requests' => $requests,
            'counts' => $counts,
            'writeoffs' => $writeOffs,
        ]);
    }

    public function hasRecords(Branch $branch): bool
    {
        return Transfer::query()->where(fn ($q) => $q->where('from_branch_id', $branch->id)->orWhere('to_branch_id', $branch->id))->exists()
            || StockRequest::query()->where('requesting_branch_id', $branch->id)->exists()
            || StockCount::query()->where('branch_id', $branch->id)->exists()
            || WriteOff::query()->where('branch_id', $branch->id)->exists()
            || CustomerReturn::query()->where('branch_id', $branch->id)->exists()
            || SupplierReturn::query()->where('branch_id', $branch->id)->exists();
    }
}
