<?php

namespace App\Policies;

use App\Models\RecurringExpense;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;

/**
 * Recurring expenses.
 * Never deleted (void / cancel with a reason instead).
 */
class RecurringExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'expenses.view_any');
    }

    public function view(User $user, RecurringExpense $recurringExpense): bool
    {
        return $user->canInBranch('expenses.view_any', $recurringExpense->branch_id);
    }

    public function create(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'expenses.recurring');
    }

    public function update(User $user, RecurringExpense $recurringExpense): bool
    {
        return $user->canInBranch('expenses.recurring', $recurringExpense->branch_id);
    }

    public function delete(User $user, RecurringExpense $recurringExpense): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
