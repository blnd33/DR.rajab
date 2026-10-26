<?php

namespace App\Policies;

use App\Models\CommissionEntry;
use App\Models\User;
use App\Services\Settings\SettingsRepository;

/**
 * Doctor commissions: only while the commissions feature is on, with commissions.manage.
 */
class CommissionEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return app(SettingsRepository::class)->bool('features.commissions') && $user->can('commissions.manage');
    }

    public function view(User $user, CommissionEntry $commissionEntry): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, CommissionEntry $commissionEntry): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, CommissionEntry $commissionEntry): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
