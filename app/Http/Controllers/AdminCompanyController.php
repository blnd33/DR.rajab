<?php

namespace App\Http\Controllers;

use App\Enums\Company;
use App\Filament\Admin\Pages\Dashboard;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * The company switch (top bar and sign-in page): work in the other company's system. Its data,
 * staff and sign-in are its own, so it opens on its Main page or asks to sign in there.
 */
class AdminCompanyController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $company = Company::tryFrom((string) $request->input('company'));
        abort_if($company === null || ! CompanyContext::isAvailable($company), 404);

        $cookie = $company->isMain()
            ? Cookie::forget(CompanyContext::COOKIE)
            : Cookie::forever(CompanyContext::COOKIE, $company->value, secure: $request->isSecure(), httpOnly: true, sameSite: 'lax');

        return redirect()->to(Dashboard::getUrl(panel: 'admin'))->withCookie($cookie);
    }
}
