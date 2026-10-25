<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

/**
 * Product category (catalog lists).
 */
class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('products.view_any');
    }

    public function view(User $user, Category $category): bool
    {
        return $user->can('products.view_any');
    }

    public function create(User $user): bool
    {
        return $user->can('settings.lists');
    }

    public function update(User $user, Category $category): bool
    {
        return $user->can('settings.lists');
    }

    public function delete(User $user, Category $category): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
