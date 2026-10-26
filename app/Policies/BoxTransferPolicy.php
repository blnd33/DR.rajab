<?php

namespace App\Policies;

use App\Models\BoxTransfer;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;

/**
 * Cash transfers, seen on either side.
 * Never deleted (void / cancel with a reason instead).
 */
class BoxTransferPolicy
{
    public function viewAny(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'cash_boxes.view_any');
    }

    public function view(User $user, BoxTransfer $boxTransfer): bool
    {
        return $user->canInBranch('cash_boxes.view_any', $boxTransfer->fromBox->branch_id)
            || $user->canInBranch('cash_boxes.view_any', $boxTransfer->toBox->branch_id);
    }

    public function create(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'cash_boxes.transfer');
    }

    public function update(User $user, BoxTransfer $boxTransfer): bool
    {
        return false;
    }

    public function delete(User $user, BoxTransfer $boxTransfer): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
