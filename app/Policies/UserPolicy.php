<?php

namespace App\Policies;

use App\Models\User;

/**
 * Users are never deleted — they are deactivated (history keeps their name).
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.view_any');
    }

    public function view(User $user, User $model): bool
    {
        return $user->id === $model->id || $user->can('users.view_any');
    }

    public function create(User $user): bool
    {
        return $user->can('users.create');
    }

    public function update(User $user, User $model): bool
    {
        // Only a Super Admin edits another Super Admin.
        if ($model->isSuperAdmin() && ! $user->isSuperAdmin()) {
            return false;
        }

        return $user->can('users.update');
    }

    public function deactivate(User $user, User $model): bool
    {
        return $user->id !== $model->id
            && $user->can('users.deactivate')
            && ($user->isSuperAdmin() || ! $model->isSuperAdmin());
    }

    public function manageSessions(User $user, User $model): bool
    {
        return $user->id === $model->id || $user->can('users.manage_sessions');
    }

    public function managePermissions(User $user, User $model): bool
    {
        return $user->id !== $model->id && $user->can('users.manage_permissions');
    }

    public function delete(User $user, User $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
