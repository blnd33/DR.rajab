<?php

namespace App\Policies;

use App\Models\Issue;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;

/**
 * Issues queue; resolving needs issues.resolve.
 * Never deleted (cancel / void with a reason instead).
 */
class IssuePolicy
{
    public function viewAny(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'issues.view_any');
    }

    public function view(User $user, Issue $issue): bool
    {
        if ($issue->branch_id === null) {
            return $user->can('issues.view_any');
        }

        return $user->canInBranch('issues.view_any', $issue->branch_id);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Issue $issue): bool
    {
        return false;
    }

    public function delete(User $user, Issue $issue): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
