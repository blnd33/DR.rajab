<?php

namespace App\Policies;

use App\Enums\Role as RoleEnum;
use App\Models\User;
use Spatie\Permission\Models\Role;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('roles.view_any');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->can('roles.view_any');
    }

    public function create(User $user): bool
    {
        return $user->can('roles.manage');
    }

    public function update(User $user, Role $role): bool
    {
        // The Super Admin role always has everything; it is not editable.
        return $role->name !== RoleEnum::SuperAdmin->value && $user->can('roles.manage');
    }

    public function delete(User $user, Role $role): bool
    {
        // Built-in role templates stay; custom roles can be removed when unused.
        return RoleEnum::tryFrom($role->name) === null
            && $user->can('roles.manage')
            && ! $role->users()->exists();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
