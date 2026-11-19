<?php

namespace App\Http\Middleware;

use App\Support\AutoLogin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test site without signing in: a visitor who is not signed in is signed in as the
 * ORTHOTRACK_AUTO_LOGIN account. Does nothing unless AutoLogin::active() (test site only).
 * Runs after the session has started and before the "who are you" checks.
 */
class AutoLoginOnTestSite
{
    public function handle(Request $request, Closure $next): Response
    {
        if (AutoLogin::active() && ! Auth::guard('web')->check() && ($user = AutoLogin::user()) !== null) {
            Auth::guard('web')->login($user);
        }

        return $next($request);
    }
}
