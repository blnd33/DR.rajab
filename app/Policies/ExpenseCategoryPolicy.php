<?php

namespace App\Policies;

use App\Models\ExpenseCategory;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;

/**
 * Expense categories: a configurable list (Module 29) — read by whoever records expenses,
 * edited with settings.lists. Switched off instead of deleted.
 */
class ExpenseCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('settings.lists') || app(BranchScopeResolver::class)->hasAny($user, 'expenses.view_any');
    }

    public function view(User $user, ExpenseCategory $expenseCategory): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('settings.lists');
    }

    public function update(User $user, ExpenseCategory $expenseCategory): bool
    {
        return $user->can('settings.lists');
    }

    public function delete(User $user, ExpenseCategory $expenseCategory): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
