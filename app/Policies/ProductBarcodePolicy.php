<?php

namespace App\Policies;

use App\Models\ProductBarcode;
use App\Models\User;

/**
 * Extra product barcodes (lookup aids).
 */
class ProductBarcodePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('products.view_any');
    }

    public function view(User $user, ProductBarcode $productBarcode): bool
    {
        return $user->can('products.view_any');
    }

    public function create(User $user): bool
    {
        return $user->can('products.manage');
    }

    public function update(User $user, ProductBarcode $productBarcode): bool
    {
        return $user->can('products.manage');
    }

    public function delete(User $user, ProductBarcode $productBarcode): bool
    {
        return $user->can('products.manage');
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
