<?php

namespace App\Policies;

use App\Models\KitCheckout;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;

/**
 * Kit checkouts of a branch; check out / return through KitService.
 * Never deleted (cancel / void with a reason instead).
 */
class KitCheckoutPolicy
{
    public function viewAny(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'kits.view_any');
    }

    public function view(User $user, KitCheckout $kitCheckout): bool
    {
        return $user->canInBranch('kits.view_any', $kitCheckout->branch_id);
    }

    public function create(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'kits.checkout');
    }

    public function update(User $user, KitCheckout $kitCheckout): bool
    {
        return false;
    }

    public function delete(User $user, KitCheckout $kitCheckout): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
