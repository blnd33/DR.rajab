<?php

namespace App\Policies;

use App\Models\CashBoxClosing;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;

/**
 * Daily closings, seen where the box is.
 * Never deleted (void / cancel with a reason instead).
 */
class CashBoxClosingPolicy
{
    public function viewAny(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'cash_boxes.view_any');
    }

    public function view(User $user, CashBoxClosing $cashBoxClosing): bool
    {
        return $user->canInBranch('cash_boxes.view_any', $cashBoxClosing->cashBox->branch_id);
    }

    public function create(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'cash_boxes.close_day');
    }

    public function update(User $user, CashBoxClosing $cashBoxClosing): bool
    {
        return false;
    }

    public function delete(User $user, CashBoxClosing $cashBoxClosing): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
