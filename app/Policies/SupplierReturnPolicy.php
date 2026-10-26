<?php

namespace App\Policies;

use App\Models\SupplierReturn;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;

/**
 * Supplier returns of a branch.
 * Never deleted (cancel / void with a reason instead).
 */
class SupplierReturnPolicy
{
    public function viewAny(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'returns.view_any');
    }

    public function view(User $user, SupplierReturn $supplierReturn): bool
    {
        return $user->canInBranch('returns.view_any', $supplierReturn->branch_id);
    }

    public function create(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'returns.supplier');
    }

    public function update(User $user, SupplierReturn $supplierReturn): bool
    {
        return false;
    }

    public function delete(User $user, SupplierReturn $supplierReturn): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
