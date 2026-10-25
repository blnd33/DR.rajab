<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

/**
 * Catalog product. Never deleted (deactivate instead) — stock history points to it.
 */
class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('products.view_any');
    }

    public function view(User $user, Product $product): bool
    {
        return $user->can('products.view_any');
    }

    public function create(User $user): bool
    {
        return $user->can('products.manage');
    }

    public function update(User $user, Product $product): bool
    {
        return $user->can('products.manage');
    }

    public function delete(User $user, Product $product): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
