<?php

namespace App\Policies;

use App\Models\CommissionRule;
use App\Models\User;
use App\Services\Settings\SettingsRepository;

/**
 * Doctor commissions: only while the commissions feature is on, with commissions.manage.
 */
class CommissionRulePolicy
{
    public function viewAny(User $user): bool
    {
        return app(SettingsRepository::class)->bool('features.commissions') && $user->can('commissions.manage');
    }

    public function view(User $user, CommissionRule $commissionRule): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, CommissionRule $commissionRule): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, CommissionRule $commissionRule): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
