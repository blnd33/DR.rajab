<?php

namespace App\Policies;

use App\Models\BranchProductSetting;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;

/**
 * Minimum stock per branch; writes are checked per branch in ProductService callers.
 */
class BranchProductSettingPolicy
{
    public function viewAny(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'stock.view');
    }

    public function view(User $user, BranchProductSetting $branchProductSetting): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'stock.view');
    }

    public function create(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'stock.min_levels');
    }

    public function update(User $user, BranchProductSetting $branchProductSetting): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'stock.min_levels');
    }

    public function delete(User $user, BranchProductSetting $branchProductSetting): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
