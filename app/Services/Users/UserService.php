<?php

namespace App\Services\Users;

use App\Models\User;
use App\Models\UserPermissionBranch;
use App\Services\Auth\SessionRegistry;
use App\Services\Permissions\BranchScopeResolver;
use App\Services\Permissions\PermissionRegistry;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

/**
 * All writes to users go through here (Filament pages only call these methods).
 */
class UserService
{
    public function __construct(
        private readonly SessionRegistry $sessions,
        private readonly BranchScopeResolver $scope,
    ) {}

    /**
     * @param  array{name: string, username: string, email?: ?string, phone?: ?string, password: string, locale?: string,
     *     all_branches?: bool, primary_branch_id?: ?int, is_active?: bool, session_timeout_minutes?: ?int,
     *     roles?: list<string>, branches?: list<int>, permissions?: list<string>}  $data
     */
    public function create(array $data, ?User $by = null): User
    {
        return DB::transaction(function () use ($data, $by) {
            $user = new User(Arr::only($data, [
                'name', 'username', 'email', 'phone', 'password', 'locale',
                'all_branches', 'primary_branch_id', 'is_active', 'session_timeout_minutes',
            ]));
            $user->password_changed_at = now();
            $user->created_by = $by?->id;
            $user->save();

            $this->syncAccess($user, $data);

            return $user;
        });
    }

    /**
     * @param  array<string, mixed>  $data  same keys as create(); password optional
     */
    public function update(User $user, array $data, ?User $by = null): User
    {
        return DB::transaction(function () use ($user, $data, $by) {
            $wasActive = $user->is_active;

            $user->fill(Arr::only($data, [
                'name', 'username', 'email', 'phone', 'locale',
                'all_branches', 'primary_branch_id', 'is_active', 'session_timeout_minutes',
            ]));

            if (filled($data['password'] ?? null)) {
                $user->password = $data['password'];
                $user->password_changed_at = now();
            }

            $user->updated_by = $by?->id;
            $user->save();

            $this->syncAccess($user, $data);

            if ($wasActive && ! $user->is_active) {
                $this->markDeactivated($user, $by);
            }

            if ($user->wasChanged('password')) {
                // Changing a password signs out every other device.
                $this->sessions->revokeAll($user, $by);
            }

            return $user;
        });
    }

    public function deactivate(User $user, ?User $by = null): void
    {
        if ($by !== null && $by->is($user)) {
            throw ValidationException::withMessages(['is_active' => __('You cannot deactivate your own account.')]);
        }

        DB::transaction(function () use ($user, $by) {
            $user->forceFill(['is_active' => false])->save();
            $this->markDeactivated($user, $by);
        });
    }

    public function reactivate(User $user, ?User $by = null): void
    {
        $user->forceFill([
            'is_active' => true,
            'deactivated_at' => null,
            'deactivated_by' => null,
            'updated_by' => $by?->id,
        ])->save();

        $this->scope->flush($user);
    }

    /**
     * Replace the per-permission branch overrides.
     *
     * @param  array<string, list<int|null>>  $overrides  permission name => branch ids (null = all branches)
     */
    public function syncPermissionBranches(User $user, array $overrides): void
    {
        DB::transaction(function () use ($user, $overrides) {
            $user->permissionBranchOverrides()->delete();

            $permissionIds = Permission::query()
                ->whereIn('name', array_keys($overrides))
                ->pluck('id', 'name');

            foreach ($overrides as $permission => $branchIds) {
                if (! isset($permissionIds[$permission]) || ! PermissionRegistry::isScoped($permission)) {
                    continue;
                }

                foreach (array_unique($branchIds === [] ? [] : $branchIds, SORT_REGULAR) as $branchId) {
                    UserPermissionBranch::query()->create([
                        'user_id' => $user->id,
                        'permission_id' => $permissionIds[$permission],
                        'branch_id' => $branchId,
                    ]);
                }
            }
        });

        $this->scope->flush($user);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncAccess(User $user, array $data): void
    {
        if (array_key_exists('roles', $data)) {
            $user->syncRoles($data['roles'] ?? []);
        }

        if (array_key_exists('permissions', $data)) {
            $permissions = array_values(array_intersect($data['permissions'] ?? [], PermissionRegistry::names()));
            $user->syncPermissions($permissions);
        }

        if (array_key_exists('branches', $data)) {
            $branches = array_map('intval', $data['branches'] ?? []);

            if ($user->primary_branch_id !== null && ! in_array($user->primary_branch_id, $branches, true) && ! $user->all_branches) {
                $branches[] = $user->primary_branch_id;
            }

            $user->branches()->sync($branches);
        }

        $this->scope->flush($user);
    }

    private function markDeactivated(User $user, ?User $by): void
    {
        $user->forceFill([
            'deactivated_at' => now(),
            'deactivated_by' => $by?->id,
            'remember_token' => null,
        ])->save();

        $this->sessions->revokeAll($user, $by);
        $this->scope->flush($user);

        activity('users')->performedOn($user)->causedBy($by)->event('deactivated')->log('User deactivated');
    }
}
