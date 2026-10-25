<?php

namespace App\Policies;

use App\Models\LowStock;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;

/**
 * Read-only view rows (v_low_stock).
 */
class LowStockPolicy
{
    public function viewAny(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'stock.view');
    }

    public function view(User $user, LowStock $lowStock): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'stock.view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, LowStock $lowStock): bool
    {
        return false;
    }

    public function delete(User $user, LowStock $lowStock): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
