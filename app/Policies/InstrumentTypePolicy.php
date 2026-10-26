<?php

namespace App\Policies;

use App\Models\InstrumentType;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;

/**
 * Instrument types: a configurable list (Module 29) — read by its users, edited with settings.lists.
 * Not deleted once used (switch off instead).
 */
class InstrumentTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('settings.lists') || app(BranchScopeResolver::class)->hasAny($user, 'kits.view_any');
    }

    public function view(User $user, InstrumentType $instrumentType): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('settings.lists');
    }

    public function update(User $user, InstrumentType $instrumentType): bool
    {
        return $user->can('settings.lists');
    }

    public function delete(User $user, InstrumentType $instrumentType): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
