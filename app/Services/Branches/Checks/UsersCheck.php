<?php

namespace App\Services\Branches\Checks;

use App\Models\Branch;
use App\Models\User;
use App\Services\Branches\Contracts\BranchDependencyCheck;

/**
 * Active users whose only branch (or primary branch) is this one must be
 * reassigned or deactivated before closing.
 */
class UsersCheck implements BranchDependencyCheck
{
    public function closureBlocker(Branch $branch): ?string
    {
        $count = User::query()
            ->active()
            ->where('all_branches', false)
            ->where(function ($query) use ($branch) {
                $query->where('primary_branch_id', $branch->id)
                    ->orWhere(function ($query) use ($branch) {
                        $query->whereHas('branches', fn ($q) => $q->whereKey($branch->id))
                            ->whereDoesntHave('branches', fn ($q) => $q->whereKeyNot($branch->id));
                    });
            })
            ->count();

        return $count > 0
            ? trans_choice(':count active user still works only in this branch — reassign or deactivate them.|:count active users still work only in this branch — reassign or deactivate them.', $count, ['count' => $count])
            : null;
    }

    public function hasRecords(Branch $branch): bool
    {
        return $branch->users()->exists()
            || User::query()->where('primary_branch_id', $branch->id)->exists();
    }
}
