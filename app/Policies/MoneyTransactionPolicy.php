<?php

namespace App\Policies;

use App\Models\User;

/**
 * Rows of a ledger or document: shown inside what the user may already open; never edited here.
 */
class MoneyTransactionPolicy
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
