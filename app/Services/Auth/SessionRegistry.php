<?php

namespace App\Services\Auth;

use App\Enums\Panel;
use App\Models\User;
use App\Models\UserSession;
use App\Support\DeviceLabel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps one `user_sessions` row per logged-in device so a user (or the Super Admin)
 * can see devices and "log out this device" — e.g. a lost phone.
 *
 * The session itself stays in Redis. Revocation sets a cache flag that the
 * device's next request sees (checked on every request, cheap), plus revoked_at.
 */
class SessionRegistry
{
    private const SESSION_KEY = 'ot.user_session_id';

    private const TOUCH_KEY = 'ot.user_session_touched_at';

    private const TOUCH_EVERY_SECONDS = 60;

    /**
     * Ensure the current request's session is registered; returns the row.
     */
    public function ensure(Request $request, User $user, ?Panel $panel): UserSession
    {
        $id = $request->session()->get(self::SESSION_KEY);

        if ($id !== null) {
            $existing = UserSession::query()->whereKey($id)->where('user_id', $user->id)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $row = UserSession::query()->create([
            'user_id' => $user->id,
            'session_id_hash' => hash('sha256', $request->session()->getId()),
            'panel' => $panel,
            'device_label' => DeviceLabel::fromUserAgent($request->userAgent()),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
            'ip_address' => $request->ip(),
            'last_activity_at' => now(),
        ]);

        $request->session()->put(self::SESSION_KEY, $row->id);
        $request->session()->put(self::TOUCH_KEY, now()->getTimestamp());

        return $row;
    }

    /**
     * Update last activity at most once a minute; returns false if the device was revoked.
     */
    public function touch(Request $request, User $user, ?Panel $panel): bool
    {
        $id = $request->session()->get(self::SESSION_KEY);

        if ($id !== null && Cache::has($this->revokedKey((int) $id))) {
            return false;
        }

        $lastTouch = (int) $request->session()->get(self::TOUCH_KEY, 0);

        if ($id !== null && now()->getTimestamp() - $lastTouch < self::TOUCH_EVERY_SECONDS) {
            return true;
        }

        $row = $this->ensure($request, $user, $panel);

        if ($row->isRevoked()) {
            return false;
        }

        $row->forceFill([
            'last_activity_at' => now(),
            'ip_address' => $request->ip(),
            'panel' => $panel ?? $row->panel,
        ])->save();

        $request->session()->put(self::TOUCH_KEY, now()->getTimestamp());

        return true;
    }

    public function revoke(UserSession $session, ?User $by = null): void
    {
        if ($session->isRevoked()) {
            return;
        }

        $session->forceFill([
            'revoked_at' => now(),
            'revoked_by' => $by?->id,
        ])->save();

        Cache::put($this->revokedKey($session->id), true, now()->addDays(2));

        activity('auth')
            ->performedOn($session->user)
            ->causedBy($by)
            ->withProperties(['device' => $session->device_label, 'ip' => $session->ip_address])
            ->event('device_logged_out')
            ->log('Device logged out');
    }

    /**
     * Revoke every device of a user (e.g. when deactivated or password changed).
     */
    public function revokeAll(User $user, ?User $by = null, ?int $exceptSessionId = null): int
    {
        $count = 0;

        $user->deviceSessions()->active()
            ->when($exceptSessionId, fn ($q) => $q->whereKeyNot($exceptSessionId))
            ->each(function (UserSession $session) use ($by, &$count) {
                $this->revoke($session, $by);
                $count++;
            });

        return $count;
    }

    /**
     * Called on logout: the current device row is closed.
     */
    public function end(Request $request): void
    {
        $id = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        if ($id === null) {
            return;
        }

        UserSession::query()->whereKey($id)->whereNull('revoked_at')->update([
            'revoked_at' => now(),
            'updated_at' => now(),
        ]);

        $request->session()->forget([self::SESSION_KEY, self::TOUCH_KEY]);
    }

    public function currentId(Request $request): ?int
    {
        $id = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        return $id === null ? null : (int) $id;
    }

    private function revokedKey(int $sessionId): string
    {
        return "ot:revoked-session:{$sessionId}";
    }
}
