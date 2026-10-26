<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

/**
 * Hospitals and other customers. Never deleted (deactivate instead).
 */
class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('customers.view_any');
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->can('customers.view_any');
    }

    public function create(User $user): bool
    {
        return $user->can('customers.manage');
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->can('customers.manage');
    }

    public function delete(User $user, Customer $customer): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
