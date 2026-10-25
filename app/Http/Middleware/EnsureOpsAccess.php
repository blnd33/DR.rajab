<?php

namespace App\Http\Middleware;

use App\Enums\Panel;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * /app/* is for users with ops.access. Admin-only users are sent to /admin.
 */
class EnsureOpsAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->canAccessAppPanel(Panel::Ops)) {
            return $next($request);
        }

        if ($user->canAccessAppPanel(Panel::Admin)) {
            return redirect()->to(Panel::Admin->homeUrl());
        }

        abort(403, __('You do not have access to the operations app.'));
    }
}
