<?php

namespace App\Policies;

use App\Models\StockBatch;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;

/**
 * Lot buckets: same rules as serial units.
 */
class StockBatchPolicy
{
    public function viewAny(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'stock.view');
    }

    public function view(User $user, StockBatch $batch): bool
    {
        return $user->canInBranch('stock.view', $batch->branch_id);
    }

    public function correct(User $user, StockBatch $batch): bool
    {
        return $batch->quantity > 0 && $user->canInBranch('stock.correct', $batch->branch_id);
    }

    public function printLabel(User $user, StockBatch $batch): bool
    {
        return $user->canInBranch('products.print_labels', $batch->branch_id);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, StockBatch $batch): bool
    {
        return false;
    }

    public function delete(User $user, StockBatch $batch): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
