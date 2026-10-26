<?php

namespace App\Policies;

use App\Models\CashBox;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;

/**
 * Cash boxes of a branch (head office = global scope). Money moves through the services only.
 * Never deleted (void / cancel with a reason instead).
 */
class CashBoxPolicy
{
    public function viewAny(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'cash_boxes.view_any');
    }

    public function view(User $user, CashBox $cashBox): bool
    {
        return $user->canInBranch('cash_boxes.view_any', $cashBox->branch_id);
    }

    public function create(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'cash_boxes.manage');
    }

    public function update(User $user, CashBox $cashBox): bool
    {
        return $user->canInBranch('cash_boxes.manage', $cashBox->branch_id);
    }

    public function delete(User $user, CashBox $cashBox): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
