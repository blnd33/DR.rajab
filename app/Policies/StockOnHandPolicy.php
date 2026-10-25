<?php

namespace App\Policies;

use App\Models\StockOnHand;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;

/**
 * Read-only view rows (v_stock_on_hand).
 */
class StockOnHandPolicy
{
    public function viewAny(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'stock.view');
    }

    public function view(User $user, StockOnHand $stockOnHand): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'stock.view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, StockOnHand $stockOnHand): bool
    {
        return false;
    }

    public function delete(User $user, StockOnHand $stockOnHand): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
