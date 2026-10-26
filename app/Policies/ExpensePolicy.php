<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;

/**
 * Expenses of a branch (head office = global scope).
 * Never deleted (void / cancel with a reason instead).
 */
class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'expenses.view_any');
    }

    public function view(User $user, Expense $expense): bool
    {
        return $user->canInBranch('expenses.view_any', $expense->branch_id);
    }

    public function create(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'expenses.create');
    }

    public function update(User $user, Expense $expense): bool
    {
        return false;
    }

    public function delete(User $user, Expense $expense): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
