<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Settings\SettingsRepository;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Super Admin must set up two-factor authentication before using /admin
 * (setting security.require_admin_mfa). The profile page stays reachable so
 * they can set it up.
 */
class RequireAdminMfa
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            ! $user instanceof User
            || ! $user->isSuperAdmin()
            || ! $this->settings->bool('security.require_admin_mfa')
            || filled($user->getAppAuthenticationSecret())
            || $request->hasHeader('X-Livewire')
            || $request->routeIs('filament.admin.auth.*')
        ) {
            return $next($request);
        }

        session()->flash('auth_notice', __('Please set up two-factor authentication to continue.'));

        return redirect()->to(Filament::getProfileUrl());
    }
}
