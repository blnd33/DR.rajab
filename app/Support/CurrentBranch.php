<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * The branch the user is working in right now (Ops app branch switcher).
 * Stored in the session; defaults to the user's primary branch.
 */
final class CurrentBranch
{
    private const SESSION_KEY = 'ops.current_branch_id';

    public static function id(): ?int
    {
        if (! app()->bound('session') || ! Auth::check()) {
            return null;
        }

        $id = session(self::SESSION_KEY);

        if ($id !== null) {
            return (int) $id;
        }

        /** @var User $user */
        $user = Auth::user();

        return $user->primary_branch_id;
    }

    public static function get(): ?Branch
    {
        $id = self::id();

        return $id === null ? null : Branch::query()->find($id);
    }

    public static function set(int $branchId): void
    {
        session([self::SESSION_KEY => $branchId]);
    }

    public static function forget(): void
    {
        session()->forget(self::SESSION_KEY);
    }
}
