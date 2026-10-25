<?php

namespace App\Http\Middleware;

use App\Enums\Panel;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Same as Filament's Authenticate, but a logged-in user without admin access
 * is sent to the operations app instead of seeing a 403 page.
 */
class FilamentAuthenticate extends Authenticate
{
    protected function authenticate($request, array $guards): void
    {
        $guard = Filament::auth();

        if (! $guard->check()) {
            // Throws AuthenticationException → redirect to the login page.
            $this->unauthenticated($request, $guards);
        }

        $this->auth->shouldUse(Filament::getAuthGuard());

        $user = $guard->user();

        if ($user instanceof User && ! $user->canAccessPanel(Filament::getCurrentOrDefaultPanel()) && $user->canAccessAppPanel(Panel::Ops)) {
            throw new HttpResponseException(redirect()->to(Panel::Ops->homeUrl()));
        }

        parent::authenticate($request, $guards);
    }
}
