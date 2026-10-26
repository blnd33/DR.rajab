<?php

namespace App\Policies;

use App\Models\SurgeryType;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;

/**
 * Surgery types: a configurable list (Module 29) — read by its users, edited with settings.lists.
 * Not deleted once used (switch off instead).
 */
class SurgeryTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('settings.lists') || app(BranchScopeResolver::class)->hasAny($user, 'surgeries.view_any');
    }

    public function view(User $user, SurgeryType $surgeryType): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('settings.lists');
    }

    public function update(User $user, SurgeryType $surgeryType): bool
    {
        return $user->can('settings.lists');
    }

    public function delete(User $user, SurgeryType $surgeryType): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
