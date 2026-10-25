<?php

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;

/**
 * Supplier master data. Never deleted (deactivate instead).
 */
class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('suppliers.view_any');
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $user->can('suppliers.view_any');
    }

    public function create(User $user): bool
    {
        return $user->can('suppliers.manage');
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $user->can('suppliers.manage');
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
