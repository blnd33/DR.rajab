<?php

namespace App\Services\Auth;

use App\Enums\LoginFailureReason;
use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Writes every login attempt to `login_attempts` and the activity log.
 * Registered as an event subscriber.
 */
class LoginAttemptLogger
{
    public function __construct(
        private readonly Request $request,
        private readonly SessionRegistry $sessions,
    ) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Login::class, [self::class, 'handleLogin']);
        $events->listen(Failed::class, [self::class, 'handleFailed']);
        $events->listen(Logout::class, [self::class, 'handleLogout']);
    }

    public function handleLogin(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $this->record($user->username, $user, true, null);

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $this->request->ip(),
        ])->saveQuietly();

        activity('auth')->performedOn($user)->causedBy($user)->event('login')->log('Logged in');
    }

    public function handleFailed(Failed $event): void
    {
        $credentials = $event->credentials;
        $tried = $credentials['username'] ?? $credentials['email'] ?? null;
        $user = $event->user instanceof User ? $event->user : null;

        $reason = $this->reasonFor($user, $credentials['password'] ?? null);

        $this->record($tried, $user, false, $reason);

        $logger = activity('auth');

        if ($user !== null) {
            $logger->performedOn($user);
        }

        $logger->withProperties(['username' => $tried, 'reason' => $reason->value])
            ->event('login_failed')
            ->log('Login failed');
    }

    public function handleLogout(Logout $event): void
    {
        $this->sessions->end($this->request);

        if ($event->user instanceof User) {
            activity('auth')->performedOn($event->user)->causedBy($event->user)->event('logout')->log('Logged out');
        }
    }

    public function recordThrottled(?string $username): void
    {
        $user = $username === null ? null : User::query()
            ->where('username', mb_strtolower($username))
            ->orWhere('email', mb_strtolower($username))
            ->first();

        $this->record($username, $user, false, LoginFailureReason::Throttled);

        activity('auth')
            ->withProperties(['username' => $username, 'reason' => LoginFailureReason::Throttled->value])
            ->event('login_throttled')
            ->log('Login throttled');
    }

    private function reasonFor(?User $user, ?string $password): LoginFailureReason
    {
        if ($user === null || $password === null || ! Hash::check($password, $user->password)) {
            return LoginFailureReason::BadCredentials;
        }

        if (! $user->is_active) {
            return LoginFailureReason::Inactive;
        }

        return LoginFailureReason::NoPanelAccess;
    }

    private function record(?string $username, ?User $user, bool $successful, ?LoginFailureReason $reason): void
    {
        LoginAttempt::query()->create([
            'username_tried' => $username === null ? null : mb_substr($username, 0, 191),
            'user_id' => $user?->id,
            'ip_address' => $this->request->ip(),
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 1000),
            'successful' => $successful,
            'failure_reason' => $reason,
        ]);
    }
}
