<?php

namespace App\Policies;

use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;

/**
 * Supplier payments (payables are global).
 * Never deleted (void / cancel with a reason instead).
 */
class SupplierPaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('purchases.view_any');
    }

    public function view(User $user, SupplierPayment $supplierPayment): bool
    {
        return $user->can('purchases.view_any');
    }

    public function create(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'supplier_payments.create');
    }

    public function update(User $user, SupplierPayment $supplierPayment): bool
    {
        return false;
    }

    public function delete(User $user, SupplierPayment $supplierPayment): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
