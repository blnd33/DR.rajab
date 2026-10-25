<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Locale\LocaleRegistry;
use App\Support\Locale\NumberLocale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locale: user setting → session (guest picker) → browser → default.
 */
class SetLocale
{
    public const SESSION_KEY = 'locale';

    public function __construct(private readonly LocaleRegistry $locales) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $locale = ($user instanceof User ? $user->locale : null)
            ?? ($request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null)
            ?? $request->getPreferredLanguage($this->locales->codes());

        $locale = $this->locales->resolve($locale);

        app()->setLocale($locale);
        Carbon::setLocale($locale);
        Number::useLocale(NumberLocale::current());

        return $next($request);
    }
}
