<?php

namespace App\Policies;

use App\Models\Purchase;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;

/**
 * Supplier invoices of the receiving branch.
 * Never deleted (void / cancel with a reason instead).
 */
class PurchasePolicy
{
    public function viewAny(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'purchases.view_any');
    }

    public function view(User $user, Purchase $purchase): bool
    {
        return $user->canInBranch('purchases.view_any', $purchase->branch_id);
    }

    public function create(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'purchases.create');
    }

    public function update(User $user, Purchase $purchase): bool
    {
        return false;
    }

    public function delete(User $user, Purchase $purchase): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
