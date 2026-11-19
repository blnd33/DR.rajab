<?php

namespace App\Policies;

use App\Models\CompanyAgent;
use App\Models\User;

/**
 * Company agents: kept by the super admin (agents.manage). Never deleted — made inactive.
 */
class CompanyAgentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('agents.manage');
    }

    public function view(User $user, CompanyAgent $agent): bool
    {
        return $user->can('agents.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('agents.manage');
    }

    public function update(User $user, CompanyAgent $agent): bool
    {
        return $user->can('agents.manage');
    }

    public function delete(User $user, CompanyAgent $agent): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
