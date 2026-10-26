<?php

namespace App\Policies;

use App\Models\KitTemplateItem;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;

/**
 * Kit template lines (inside a kit type): a configurable list (Module 29) — read by its users, edited with settings.lists.
 * Not deleted once used (switch off instead).
 */
class KitTemplateItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('settings.lists') || app(BranchScopeResolver::class)->hasAny($user, 'kits.view_any');
    }

    public function view(User $user, KitTemplateItem $kitTemplateItem): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('settings.lists');
    }

    public function update(User $user, KitTemplateItem $kitTemplateItem): bool
    {
        return $user->can('settings.lists');
    }

    public function delete(User $user, KitTemplateItem $kitTemplateItem): bool
    {
        return $user->can('settings.lists');
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
