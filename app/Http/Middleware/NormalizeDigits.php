<?php

namespace App\Http\Middleware;

use App\Support\Locale\DigitConverter;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Converts Arabic-Indic / Persian digits in request input to Western digits
 * before validation. Livewire update requests are handled by a Livewire
 * `request` hook (see AppServiceProvider) because their payload has a
 * checksummed snapshot that must not be touched.
 */
class NormalizeDigits
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasHeader('X-Livewire')) {
            $request->query->replace(DigitConverter::normalizeArray($request->query->all()));

            if ($request->isJson()) {
                $request->json()->replace(DigitConverter::normalizeArray($request->json()->all()));
            } else {
                $request->request->replace(DigitConverter::normalizeArray($request->request->all()));
            }
        }

        return $next($request);
    }
}
