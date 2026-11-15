<?php

namespace App\Services\Permissions;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Resolves the "where" part of a permission: which branches a user may act on.
 *
 *   1. No permission                  → no branches.
 *   2. Super Admin                    → all branches.
 *   3. Unscoped (global) permission   → all branches.
 *   4. Per-permission override rows   → those branches (a NULL row = all).
 *   5. Default                        → all_branches ? all : the user's assigned branches.
 *
 * Registered as a scoped singleton: results are memoised per request.
 */
class BranchScopeResolver
{
    /** @var array<int, array<string, list<int>|null>> */
    private array $memo = [];

    /** @var array<int, array<string, list<int|null>>> */
    private array $overrides = [];

    /** The branch the signed-in admin chose in the top bar (only for that user, this request). */
    private ?int $focusBranch = null;

    private ?int $focusUser = null;

    /**
     * @return list<int>|null null = all branches
     */
    public function branchIdsFor(User $user, string $permission): ?array
    {
        return $this->narrow($user, $permission, $this->unfocusedBranchIdsFor($user, $permission));
    }

    /**
     * The user's full scope, ignoring the branch chosen in the top bar.
     *
     * @return list<int>|null null = all branches
     */
    public function unfocusedBranchIdsFor(User $user, string $permission): ?array
    {
        if (array_key_exists($permission, $this->memo[$user->id] ?? [])) {
            return $this->memo[$user->id][$permission];
        }

        return $this->memo[$user->id][$permission] = $this->resolve($user, $permission);
    }

    /**
     * Work in one branch: every branch-based permission of this user is narrowed to it (lists,
     * pickers, dashboard, reports, graphs and actions). Null = all the user's branches.
     */
    public function focus(User $user, ?int $branchId): void
    {
        $this->focusBranch = $branchId;
        $this->focusUser = $branchId === null ? null : $user->id;
    }

    public function focusedBranch(User $user): ?int
    {
        return $this->focusUser === $user->id ? $this->focusBranch : null;
    }

    /**
     * @param  list<int>|null  $ids
     * @return list<int>|null
     */
    private function narrow(User $user, string $permission, ?array $ids): ?array
    {
        $focus = $this->focusedBranch($user);

        if ($focus === null || ! PermissionRegistry::isScoped($permission)) {
            return $ids;
        }

        return $ids === null || in_array($focus, $ids, true) ? [$focus] : [];
    }

    public function allows(User $user, string $permission, Branch|int|null $branch): bool
    {
        $ids = $this->branchIdsFor($user, $permission);

        if ($ids === null) {
            return true;
        }

        if ($branch === null) {
            return false;
        }

        $branchId = $branch instanceof Branch ? $branch->getKey() : $branch;

        return in_array($branchId, $ids, true);
    }

    /**
     * True when the user has the permission in at least one branch.
     */
    public function hasAny(User $user, string $permission): bool
    {
        $ids = $this->branchIdsFor($user, $permission);

        return $ids === null || $ids !== [];
    }

    /**
     * Restrict a query to rows whose branch column is in the user's scope.
     *
     * @template TBuilder of Builder|QueryBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function apply(Builder|QueryBuilder $query, User $user, string $permission, string $column = 'branch_id'): Builder|QueryBuilder
    {
        $ids = $this->branchIdsFor($user, $permission);

        if ($ids === null) {
            return $query;
        }

        return $ids === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn($column, $ids);
    }

    /**
     * Branches the user may pick for an action (open branches only).
     *
     * @return Builder<Branch>
     */
    public function selectableBranches(User $user, string $permission): Builder
    {
        return $this->apply(Branch::query()->active()->orderBy('code'), $user, $permission, 'id');
    }

    public function flush(?User $user = null): void
    {
        if ($user === null) {
            $this->memo = [];
            $this->overrides = [];

            return;
        }

        unset($this->memo[$user->id], $this->overrides[$user->id]);
    }

    /**
     * @return list<int>|null
     */
    private function resolve(User $user, string $permission): ?array
    {
        if (! $user->is_active) {
            return [];
        }

        if ($user->isSuperAdmin()) {
            return null;
        }

        if (! $user->can($permission)) {
            return [];
        }

        if (! PermissionRegistry::isScoped($permission)) {
            return null;
        }

        $overrides = $this->overridesFor($user)[$permission] ?? null;

        if ($overrides !== null) {
            return in_array(null, $overrides, true)
                ? null
                : array_values(array_map('intval', $overrides));
        }

        if ($user->all_branches) {
            return null;
        }

        return $user->branches()->pluck('branches.id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * permission name => list of branch ids (null entry = all branches)
     *
     * @return array<string, list<int|null>>
     */
    private function overridesFor(User $user): array
    {
        if (! isset($this->overrides[$user->id])) {
            $this->overrides[$user->id] = $user->permissionBranchOverrides()
                ->join('permissions', 'permissions.id', '=', 'user_permission_branches.permission_id')
                ->get(['permissions.name', 'user_permission_branches.branch_id'])
                ->groupBy('name')
                ->map(fn ($rows) => $rows->pluck('branch_id')->all())
                ->all();
        }

        return $this->overrides[$user->id];
    }
}
