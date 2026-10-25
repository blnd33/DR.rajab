<?php

namespace App\Http\Responses;

use App\Models\User;
use App\Services\Auth\LoginRedirector;
use Filament\Auth\Http\Responses\Contracts\LoginResponse as LoginResponseContract;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * After login: intended URL if the user may open it, else their role's panel.
 */
class LoginResponse implements LoginResponseContract
{
    public function __construct(private readonly LoginRedirector $redirector) {}

    public function toResponse($request): RedirectResponse|Redirector
    {
        /** @var User $user */
        $user = $request->user();

        $intended = session()->pull('url.intended');
        $url = $this->redirector->urlAfterLogin($user, $intended) ?? url('/');

        return redirect()->to($url);
    }
}
