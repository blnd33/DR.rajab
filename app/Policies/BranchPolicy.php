<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;

/**
 * "Delete" in the UI is Close. A true delete is only allowed for a branch with
 * no records at all (checked by BranchService).
 */
class BranchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('branches.view_any');
    }

    public function view(User $user, Branch $branch): bool
    {
        return $user->can('branches.view_any');
    }

    public function create(User $user): bool
    {
        return $user->can('branches.create');
    }

    public function update(User $user, Branch $branch): bool
    {
        return $user->can('branches.update');
    }

    public function close(User $user, Branch $branch): bool
    {
        return $branch->isActive() && $user->can('branches.close');
    }

    public function reopen(User $user, Branch $branch): bool
    {
        return ! $branch->isActive() && $user->can('branches.close');
    }

    public function delete(User $user, Branch $branch): bool
    {
        return $user->can('branches.delete');
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
