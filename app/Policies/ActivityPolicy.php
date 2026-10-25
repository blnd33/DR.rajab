<?php

namespace App\Policies;

use App\Models\Activity;
use App\Models\User;

/**
 * The activity log is read-only for everyone, including the Super Admin.
 * (A database trigger also rejects UPDATE/DELETE.)
 */
class ActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('activity.view_any');
    }

    public function view(User $user, Activity $activity): bool
    {
        if (! $user->can('activity.view_any')) {
            return false;
        }

        // Entries without a branch (logins, settings) are visible to anyone with the permission.
        return $activity->branch_id === null || $user->canInBranch('activity.view_any', $activity->branch_id);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Activity $activity): bool
    {
        return false;
    }

    public function delete(User $user, Activity $activity): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
