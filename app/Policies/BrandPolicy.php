<?php

namespace App\Policies;

use App\Models\Brand;
use App\Models\User;

/**
 * Brand / manufacturer (catalog lists).
 */
class BrandPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('products.view_any');
    }

    public function view(User $user, Brand $brand): bool
    {
        return $user->can('products.view_any');
    }

    public function create(User $user): bool
    {
        return $user->can('settings.lists');
    }

    public function update(User $user, Brand $brand): bool
    {
        return $user->can('settings.lists');
    }

    public function delete(User $user, Brand $brand): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
