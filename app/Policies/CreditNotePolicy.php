<?php

namespace App\Policies;

use App\Models\CreditNote;
use App\Models\User;

/**
 * Credit notes (supplier / customer balances are global).
 * Never deleted (void / cancel with a reason instead).
 */
class CreditNotePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('purchases.view_any');
    }

    public function view(User $user, CreditNote $creditNote): bool
    {
        return $user->can('purchases.view_any');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, CreditNote $creditNote): bool
    {
        return false;
    }

    public function delete(User $user, CreditNote $creditNote): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
