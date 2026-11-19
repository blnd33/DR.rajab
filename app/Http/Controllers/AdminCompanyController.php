<?php

namespace App\Http\Controllers;

use App\Enums\Company;
use App\Filament\Admin\Pages\Dashboard;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * The button next to the company name. KurdishMedicus → Al-Fadhaa' Bureau: a one-minute signed
 * link that signs the same person in there (CompanyEnterController) — no login of its own.
 * Al-Fadhaa' Bureau → KurdishMedicus: back to the main company (its own sign-in).
 */
class AdminCompanyController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $company = Company::tryFrom((string) $request->input('company'));
        abort_if($company === null || ! CompanyContext::isAvailable($company), 404);
        abort_unless($user->can('companies.switch'), 403);

        if ($company->isMain()) {
            return redirect()->to(Dashboard::getUrl(panel: 'admin'))->withCookie(Cookie::forget(CompanyContext::COOKIE));
        }

        $enter = URL::temporarySignedRoute('company.enter', now()->addMinute(), [
            'company' => $company->value,
            'username' => $user->username,
            'name' => $user->name,
            'email' => $user->email,
            'locale' => $user->locale ?? app()->getLocale(),
            'once' => Str::random(40),
        ]);

        return redirect()->to($enter)
            ->withCookie(Cookie::forever(CompanyContext::COOKIE, $company->value, secure: $request->isSecure(), httpOnly: true, sameSite: 'lax'));
    }
}
