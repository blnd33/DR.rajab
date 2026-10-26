<?php

namespace App\Policies;

use App\Models\StockRequest;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;

/**
 * Stock requests of the requesting branch; decisions through StockRequestService.
 * Never deleted (cancel / void with a reason instead).
 */
class StockRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'stock_requests.view_any');
    }

    public function view(User $user, StockRequest $stockRequest): bool
    {
        return $user->canInBranch('stock_requests.view_any', $stockRequest->requesting_branch_id)
            || $user->canInBranch('stock_requests.view_any', $stockRequest->preferred_source_branch_id);
    }

    public function create(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'stock_requests.create');
    }

    public function update(User $user, StockRequest $stockRequest): bool
    {
        return false;
    }

    public function delete(User $user, StockRequest $stockRequest): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
