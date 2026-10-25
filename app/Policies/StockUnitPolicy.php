<?php

namespace App\Policies;

use App\Models\StockUnit;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;

/**
 * Serial units: visible in the branches where the user has stock.view. Never edited or
 * deleted directly — every change is a StockMovementService call (correct, override…).
 */
class StockUnitPolicy
{
    public function viewAny(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'stock.view');
    }

    public function view(User $user, StockUnit $unit): bool
    {
        return $user->canInBranch('stock.view', $unit->branch_id);
    }

    public function correct(User $user, StockUnit $unit): bool
    {
        return $unit->status->isOnHand() && $user->canInBranch('stock.correct', $unit->branch_id);
    }

    public function overrideStatus(User $user, StockUnit $unit): bool
    {
        return $user->canInBranch('stock.adjust', $unit->branch_id);
    }

    public function printLabel(User $user, StockUnit $unit): bool
    {
        return $user->canInBranch('products.print_labels', $unit->branch_id);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, StockUnit $unit): bool
    {
        return false;
    }

    public function delete(User $user, StockUnit $unit): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
