<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * The branch chosen in the admin top bar. Everything branch-based in the admin follows it
 * (BranchScopeResolver::focus); null = all the user's branches. Stored in the session.
 */
final class AdminBranch
{
    public const SESSION_KEY = 'admin.branch_id';

    public static function id(): ?int
    {
        if (! app()->bound('session')) {
            return null;
        }

        $id = session(self::SESSION_KEY);

        return $id === null ? null : (int) $id;
    }

    public static function set(?int $branchId): void
    {
        $branchId === null ? session()->forget(self::SESSION_KEY) : session([self::SESSION_KEY => $branchId]);
    }

    /**
     * Branches the user may switch to: every open branch for Super Admin and "all branches"
     * accounts, otherwise the branches the account belongs to.
     *
     * @return Collection<int, Branch>
     */
    public static function options(User $user): Collection
    {
        $query = Branch::query()->active()->orderBy('code');

        if (! $user->isSuperAdmin() && ! $user->all_branches) {
            $ids = $user->branches()->pluck('branches.id')->push($user->primary_branch_id)->filter()->unique()->all();
            $query->whereIn('id', $ids === [] ? [0] : $ids);
        }

        return $query->get();
    }

    public static function allows(User $user, int $branchId): bool
    {
        return self::options($user)->contains('id', $branchId);
    }
}
