<?php

namespace App\Policies;

use App\Models\Surgery;
use App\Models\User;
use App\Services\Clinical\SurgeryService;
use App\Services\Permissions\BranchScopeResolver;

/**
 * Surgeries: in the user's surgeries.view_any branches; without patients.view_all only the
 * ones they operated on or logged. Changes go through SurgeryService; never deleted.
 */
class SurgeryPolicy
{
    public function viewAny(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'surgeries.view_any');
    }

    public function view(User $user, Surgery $surgery): bool
    {
        return app(SurgeryService::class)->visibleTo($user)->whereKey($surgery->id)->exists()
            || ($surgery->created_by === $user->id && $user->canInBranch('surgeries.create', $surgery->branch_id));
    }

    public function create(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'surgeries.create');
    }

    public function update(User $user, Surgery $surgery): bool
    {
        return false;
    }

    public function delete(User $user, Surgery $surgery): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
