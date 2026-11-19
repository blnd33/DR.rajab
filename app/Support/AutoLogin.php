<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;

/**
 * Test site without signing in (ORTHOTRACK_AUTO_LOGIN, only with ORTHOTRACK_TEST_SITE=true):
 * every visitor is signed in as one account, with no login page and no two-factor step.
 * On any other site this is always off.
 */
final class AutoLogin
{
    public static function active(): bool
    {
        $value = config('orthotrack.auto_login');

        return (bool) config('orthotrack.test_site')
            && filled($value)
            && ! in_array(strtolower(trim((string) $value)), ['false', '0', 'off', 'no'], true);
    }

    /**
     * The account visitors get: the username given, or the first active super admin for "true".
     */
    public static function user(): ?User
    {
        if (! self::active()) {
            return null;
        }

        $value = trim((string) config('orthotrack.auto_login'));

        if (! in_array(strtolower($value), ['true', '1', 'on', 'yes'], true)) {
            return User::query()->where('username', strtolower($value))->where('is_active', true)->first();
        }

        return User::query()->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('name', Role::SuperAdmin->value))
            ->orderBy('id')->first();
    }
}
