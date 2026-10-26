<?php

namespace App\Policies;

use App\Models\Kit;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;

/**
 * Kits of a branch (current or home). Status changes go through KitService; never deleted
 * (retire instead).
 */
class KitPolicy
{
    public function viewAny(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'kits.view_any');
    }

    public function view(User $user, Kit $kit): bool
    {
        return $user->canInBranch('kits.view_any', $kit->branch_id) || $user->canInBranch('kits.view_any', $kit->home_branch_id);
    }

    public function create(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'kits.manage');
    }

    public function update(User $user, Kit $kit): bool
    {
        return $user->canInBranch('kits.manage', $kit->branch_id);
    }

    public function delete(User $user, Kit $kit): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
