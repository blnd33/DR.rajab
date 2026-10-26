<?php

namespace App\Policies;

use App\Models\CurrencyExchange;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;

/**
 * Currency exchanges of a branch.
 * Never deleted (void / cancel with a reason instead).
 */
class CurrencyExchangePolicy
{
    public function viewAny(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'cash_boxes.view_any');
    }

    public function view(User $user, CurrencyExchange $currencyExchange): bool
    {
        return $user->canInBranch('cash_boxes.view_any', $currencyExchange->branch_id);
    }

    public function create(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'currency_exchange.create');
    }

    public function update(User $user, CurrencyExchange $currencyExchange): bool
    {
        return false;
    }

    public function delete(User $user, CurrencyExchange $currencyExchange): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
