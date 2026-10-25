<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Auth\LoginRedirector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class HomeController extends Controller
{
    public function __invoke(Request $request, LoginRedirector $redirector): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return redirect()->route('login');
        }

        $url = $redirector->homeUrl($user);

        if ($url === null) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            return redirect()->route('login')->with('auth_notice', __('Your account has no access to any panel. Please contact the administrator.'));
        }

        return redirect()->to($url);
    }
}
