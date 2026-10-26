<?php

namespace App\Policies;

use App\Models\KitType;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;

/**
 * Kit types and their templates: a configurable list (Module 29) — read by its users, edited with settings.lists.
 * Not deleted once used (switch off instead).
 */
class KitTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('settings.lists') || app(BranchScopeResolver::class)->hasAny($user, 'kits.view_any');
    }

    public function view(User $user, KitType $kitType): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('settings.lists');
    }

    public function update(User $user, KitType $kitType): bool
    {
        return $user->can('settings.lists');
    }

    public function delete(User $user, KitType $kitType): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
