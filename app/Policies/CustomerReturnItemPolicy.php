<?php

namespace App\Policies;

use App\Models\User;

/**
 * Lines of a document: shown inside the document the user may already open; never edited here.
 */
class CustomerReturnItemPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
