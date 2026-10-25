<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\LoginRedirector;
use App\Services\Auth\SessionRegistry;
use App\Services\Settings\SettingsRepository;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * For every authenticated request (both panels):
 *  - deactivated user → logged out
 *  - device revoked ("log out this device") → logged out
 *  - idle longer than the timeout (shared devices) → logged out
 *  - otherwise the device row's last activity is updated.
 */
class TrackUserSession
{
    private const LAST_SEEN_KEY = 'ot.last_seen_at';

    public function __construct(
        private readonly SessionRegistry $sessions,
        private readonly SettingsRepository $settings,
        private readonly LoginRedirector $redirector,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if (! $user->is_active) {
            return $this->logout($request, __('Your account has been deactivated.'));
        }

        if ($this->isIdleTooLong($request, $user)) {
            return $this->logout($request, __('You were logged out after a period of inactivity.'));
        }

        $panel = $this->redirector->panelForPath($request->path());

        if (! $this->sessions->touch($request, $user, $panel)) {
            return $this->logout($request, __('This device was logged out.'));
        }

        $request->session()->put(self::LAST_SEEN_KEY, now()->getTimestamp());

        return $next($request);
    }

    private function isIdleTooLong(Request $request, User $user): bool
    {
        $lastSeen = $request->session()->get(self::LAST_SEEN_KEY);

        if ($lastSeen === null) {
            return false;
        }

        $minutes = $user->session_timeout_minutes ?: $this->settings->int('security.session_timeout_minutes');

        return $minutes > 0 && (now()->getTimestamp() - (int) $lastSeen) > $minutes * 60;
    }

    private function logout(Request $request, string $message): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->flash('auth_notice', $message);

        if ($request->hasHeader('X-Livewire') || $request->expectsJson()) {
            abort(401, $message);
        }

        return redirect()->route('login');
    }
}
