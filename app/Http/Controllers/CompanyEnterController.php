<?php

namespace App\Http\Controllers;

use App\Filament\Admin\Pages\Dashboard;
use App\Services\Companies\CompanyAccountService;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Arriving in Al-Fadhaa' Bureau from KurdishMedicus' button (a signed, one-minute, one-time link):
 * the same person is signed in there, with their account kept in step (CompanyAccountService).
 */
class CompanyEnterController extends Controller
{
    public function __invoke(Request $request, CompanyAccountService $accounts): RedirectResponse
    {
        // The link is only for the company this request works in, and only once.
        abort_unless(CompanyContext::current()->value === $request->query('company') && ! CompanyContext::current()->isMain(), 404);
        abort_unless(Cache::add('company-enter:'.$request->query('once'), true, now()->addMinutes(5)), 403);

        $user = $accounts->accountFor([
            'username' => (string) $request->query('username'),
            'name' => (string) $request->query('name'),
            'email' => $request->query('email'),
            'locale' => (string) $request->query('locale'),
        ]);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->to(Dashboard::getUrl(panel: 'admin'));
    }
}
