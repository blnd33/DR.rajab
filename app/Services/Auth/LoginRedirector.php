<?php

namespace App\Services\Auth;

use App\Enums\Panel;
use App\Models\User;

/**
 * Single login → the right front-end. Super Admin / Accountant land on /admin,
 * everyone else on /app. Users with access to both get a panel switcher.
 */
class LoginRedirector
{
    public function defaultPanel(User $user): ?Panel
    {
        $preferred = $user->primaryRole()?->defaultPanel();

        if ($preferred !== null && $user->canAccessAppPanel($preferred)) {
            return $preferred;
        }

        foreach ([Panel::Ops, Panel::Admin] as $panel) {
            if ($user->canAccessAppPanel($panel)) {
                return $panel;
            }
        }

        return null;
    }

    public function homeUrl(User $user): ?string
    {
        return $this->defaultPanel($user)?->homeUrl();
    }

    /**
     * Respect the "intended" URL only when it points into a panel the user may open.
     */
    public function urlAfterLogin(User $user, ?string $intended): ?string
    {
        if ($intended !== null && $this->userCanOpenUrl($user, $intended)) {
            return $intended;
        }

        return $this->homeUrl($user);
    }

    public function panelForPath(string $path): ?Panel
    {
        $path = '/'.ltrim($path, '/');

        return match (true) {
            $path === '/admin' || str_starts_with($path, '/admin/') => Panel::Admin,
            $path === '/app' || str_starts_with($path, '/app/') => Panel::Ops,
            default => null,
        };
    }

    private function userCanOpenUrl(User $user, string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';

        // Never send someone back to a login/logout URL.
        if (str_contains($path, '/login') || str_contains($path, '/logout')) {
            return false;
        }

        $panel = $this->panelForPath($path);

        return $panel !== null && $user->canAccessAppPanel($panel);
    }
}
