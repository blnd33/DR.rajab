<?php

namespace App\Policies;

use App\Models\CustomerPayment;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;

/**
 * Customer payments, seen where the box is.
 * Never deleted (void / cancel with a reason instead).
 */
class CustomerPaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'sales.view_any');
    }

    public function view(User $user, CustomerPayment $customerPayment): bool
    {
        return $user->canInBranch('sales.view_any', $customerPayment->cashBox->branch_id);
    }

    public function create(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'customer_payments.create');
    }

    public function update(User $user, CustomerPayment $customerPayment): bool
    {
        return false;
    }

    public function delete(User $user, CustomerPayment $customerPayment): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
