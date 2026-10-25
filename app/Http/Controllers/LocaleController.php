<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use App\Models\User;
use App\Support\Locale\LocaleRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocaleController extends Controller
{
    public function __invoke(Request $request, string $locale, LocaleRegistry $locales): RedirectResponse
    {
        abort_unless($locales->isSupported($locale), 404);

        $request->session()->put(SetLocale::SESSION_KEY, $locale);

        $user = $request->user();

        if ($user instanceof User && $user->locale !== $locale) {
            $user->update(['locale' => $locale]);
        }

        return redirect()->back();
    }
}
