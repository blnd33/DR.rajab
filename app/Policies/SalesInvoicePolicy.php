<?php

namespace App\Policies;

use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;

/**
 * Sales of a branch.
 * Never deleted (void / cancel with a reason instead).
 */
class SalesInvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'sales.view_any');
    }

    public function view(User $user, SalesInvoice $salesInvoice): bool
    {
        return $user->canInBranch('sales.view_any', $salesInvoice->branch_id);
    }

    public function create(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'sales.create');
    }

    public function update(User $user, SalesInvoice $salesInvoice): bool
    {
        return false;
    }

    public function delete(User $user, SalesInvoice $salesInvoice): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
