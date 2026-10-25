<?php

namespace App\Policies;

use App\Models\BranchLocation;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;

/**
 * Shelves of a branch. Deactivated, never deleted.
 */
class BranchLocationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('branches.view_any') || app(BranchScopeResolver::class)->hasAny($user, 'stock.view');
    }

    public function view(User $user, BranchLocation $branchLocation): bool
    {
        return $user->can('branches.view_any') || app(BranchScopeResolver::class)->hasAny($user, 'stock.view');
    }

    public function create(User $user): bool
    {
        return $user->can('branches.update');
    }

    public function update(User $user, BranchLocation $branchLocation): bool
    {
        return $user->can('branches.update');
    }

    public function delete(User $user, BranchLocation $branchLocation): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
