<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WriteOff;
use App\Services\Permissions\BranchScopeResolver;

/**
 * Write-offs of a branch; approval through WriteOffService.
 * Never deleted (cancel / void with a reason instead).
 */
class WriteOffPolicy
{
    public function viewAny(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'write_offs.view_any');
    }

    public function view(User $user, WriteOff $writeOff): bool
    {
        return $user->canInBranch('write_offs.view_any', $writeOff->branch_id);
    }

    public function create(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'write_offs.report');
    }

    public function update(User $user, WriteOff $writeOff): bool
    {
        return false;
    }

    public function delete(User $user, WriteOff $writeOff): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
