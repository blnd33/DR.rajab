<?php

namespace App\Policies;

use App\Models\StockCount;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;

/**
 * Stock counts of a branch.
 * Never deleted (cancel / void with a reason instead).
 */
class StockCountPolicy
{
    public function viewAny(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'stock_counts.view_any');
    }

    public function view(User $user, StockCount $stockCount): bool
    {
        return $user->canInBranch('stock_counts.view_any', $stockCount->branch_id);
    }

    public function create(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'stock_counts.start');
    }

    public function update(User $user, StockCount $stockCount): bool
    {
        return false;
    }

    public function delete(User $user, StockCount $stockCount): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
