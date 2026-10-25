<?php

namespace App\Policies;

use App\Models\StockMovement;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;

/**
 * The stock ledger is read-only for everyone (append-only in the database too).
 */
class StockMovementPolicy
{
    public function viewAny(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'stock.view');
    }

    public function view(User $user, StockMovement $movement): bool
    {
        return $user->canInBranch('stock.view', $movement->to_branch_id)
            || $user->canInBranch('stock.view', $movement->from_branch_id);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, StockMovement $movement): bool
    {
        return false;
    }

    public function delete(User $user, StockMovement $movement): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
