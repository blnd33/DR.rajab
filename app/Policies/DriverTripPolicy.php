<?php

namespace App\Policies;

use App\Models\DriverTrip;
use App\Models\User;

/**
 * Driver trips: recorded by the super admin (agents.manage). Never deleted — voided with a reason.
 */
class DriverTripPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('agents.manage');
    }

    public function view(User $user, DriverTrip $trip): bool
    {
        return $user->can('agents.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('agents.manage');
    }

    public function update(User $user, DriverTrip $trip): bool
    {
        return $user->can('agents.manage') && ! $trip->isVoided();
    }

    public function delete(User $user, DriverTrip $trip): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
