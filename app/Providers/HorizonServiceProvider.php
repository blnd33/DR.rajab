<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        Horizon::routeMailNotificationsTo(config('orthotrack.ops_admin_email'));
    }

    /**
     * /horizon is for the Coda ops team (system.health) and the Super Admin.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $user = null) => $user !== null && $user->is_active && $user->can('system.health'));
    }
}
