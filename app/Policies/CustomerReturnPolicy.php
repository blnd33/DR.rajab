<?php

namespace App\Policies;

use App\Models\CustomerReturn;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;

/**
 * Customer returns of a branch.
 * Never deleted (cancel / void with a reason instead).
 */
class CustomerReturnPolicy
{
    public function viewAny(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'returns.view_any');
    }

    public function view(User $user, CustomerReturn $customerReturn): bool
    {
        return $user->canInBranch('returns.view_any', $customerReturn->branch_id);
    }

    public function create(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'returns.customer');
    }

    public function update(User $user, CustomerReturn $customerReturn): bool
    {
        return false;
    }

    public function delete(User $user, CustomerReturn $customerReturn): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
