<?php

namespace App\Policies;

use App\Models\Instrument;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;

/**
 * Instruments of a branch. Status changes go through InstrumentService / KitService;
 * never deleted (retire instead).
 */
class InstrumentPolicy
{
    public function viewAny(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'kits.view_any');
    }

    public function view(User $user, Instrument $instrument): bool
    {
        return $user->canInBranch('kits.view_any', $instrument->branch_id);
    }

    public function create(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'instruments.manage');
    }

    public function update(User $user, Instrument $instrument): bool
    {
        return $user->canInBranch('instruments.manage', $instrument->branch_id);
    }

    public function delete(User $user, Instrument $instrument): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
