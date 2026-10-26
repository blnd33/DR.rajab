<?php

namespace App\Policies;

use App\Models\Transfer;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;

/**
 * Transfers: visible to branches on either side; changes go through TransferService.
 * Never deleted (cancel / void with a reason instead).
 */
class TransferPolicy
{
    public function viewAny(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'transfers.view_any');
    }

    public function view(User $user, Transfer $transfer): bool
    {
        return $user->canInBranch('transfers.view_any', $transfer->from_branch_id)
            || $user->canInBranch('transfers.view_any', $transfer->to_branch_id);
    }

    public function create(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'transfers.create');
    }

    public function update(User $user, Transfer $transfer): bool
    {
        return false;
    }

    public function delete(User $user, Transfer $transfer): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
