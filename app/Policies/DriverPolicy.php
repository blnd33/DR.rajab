<?php

namespace App\Policies;

use App\Models\Driver;
use App\Models\User;

/**
 * Drivers: kept by the super admin (agents.manage). Never deleted — made inactive.
 */
class DriverPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('agents.manage');
    }

    public function view(User $user, Driver $driver): bool
    {
        return $user->can('agents.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('agents.manage');
    }

    public function update(User $user, Driver $driver): bool
    {
        return $user->can('agents.manage');
    }

    public function delete(User $user, Driver $driver): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
