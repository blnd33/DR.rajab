<?php

namespace App\Policies;

use App\Models\Uom;
use App\Models\User;

/**
 * Unit of measure (catalog lists).
 */
class UomPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('products.view_any');
    }

    public function view(User $user, Uom $uom): bool
    {
        return $user->can('products.view_any');
    }

    public function create(User $user): bool
    {
        return $user->can('settings.lists');
    }

    public function update(User $user, Uom $uom): bool
    {
        return $user->can('settings.lists');
    }

    public function delete(User $user, Uom $uom): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
