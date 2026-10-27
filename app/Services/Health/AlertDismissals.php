<?php

namespace App\Services\Health;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * "Dismiss" on the dashboard's system alerts: hides them for one user until a problem is new or
 * changes level (warning → critical), and for 24 hours at most, so a real problem can't stay
 * hidden. The problems themselves stay on the System health page.
 */
class AlertDismissals
{
    public const HOURS = 24;

    /**
     * @param  list<array{key: string, status: string}>  $problems
     */
    public function dismiss(User $user, array $problems): void
    {
        Cache::put($this->cacheKey($user), $this->fingerprint($problems), now()->addHours(self::HOURS));

        activity('system')
            ->causedBy($user)
            ->withProperties(['problems' => array_map(fn (array $p) => $p['key'].':'.$p['status'], $problems)])
            ->event('system_alerts_dismissed')
            ->log('System alerts dismissed');
    }

    /**
     * @param  list<array{key: string, status: string}>  $problems
     */
    public function isDismissed(User $user, array $problems): bool
    {
        $stored = Cache::get($this->cacheKey($user));

        // Same problems at the same level: still dismissed. A problem that went away keeps them
        // hidden too (fewer problems is no reason to show the strip again).
        return is_array($stored) && array_diff($this->fingerprint($problems), $stored) === [];
    }

    /**
     * Detail text (free GB, dates) changes all the time, so only which check and its level count.
     *
     * @param  list<array{key: string, status: string}>  $problems
     * @return list<string>
     */
    private function fingerprint(array $problems): array
    {
        $keys = array_map(fn (array $p) => $p['key'].':'.$p['status'], $problems);
        sort($keys);

        return $keys;
    }

    private function cacheKey(User $user): string
    {
        return 'system-alerts.dismissed.'.$user->id;
    }
}
