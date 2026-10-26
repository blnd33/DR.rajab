<?php

namespace App\Policies;

use App\Models\ExchangeRate;
use App\Models\User;

/**
 * Exchange rates (global list; setting a rate needs the pair permission).
 * Never deleted (void / cancel with a reason instead).
 */
class ExchangeRatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('exchange_rates.view_any');
    }

    public function view(User $user, ExchangeRate $exchangeRate): bool
    {
        return $user->can('exchange_rates.view_any');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ExchangeRate $exchangeRate): bool
    {
        return false;
    }

    public function delete(User $user, ExchangeRate $exchangeRate): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
