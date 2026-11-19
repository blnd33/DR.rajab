<?php

namespace App\Http\Middleware;

use App\Support\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Al-Fadhaa' Bureau has no login of its own: someone not signed in there (e.g. the session ran out)
 * goes back to KurdishMedicus, where they sign in and use the button again.
 */
class SendGuestsToMainCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! CompanyContext::current()->isMain() && Auth::guard('web')->guest() && ! $request->routeIs('company.enter')) {
            return redirect()->to('/admin/login')->withCookie(Cookie::forget(CompanyContext::COOKIE));
        }

        return $next($request);
    }
}
