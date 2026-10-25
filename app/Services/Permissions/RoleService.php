<?php

namespace App\Services\Permissions;

use App\Enums\Role as RoleEnum;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates permissions from the registry and keeps role templates in sync.
 */
class RoleService
{
    public function __construct(private readonly BranchScopeResolver $scope) {}

    /**
     * Make sure every registry permission exists (idempotent; run by the seeder and on deploy).
     */
    public function syncPermissionsFromRegistry(): int
    {
        $created = 0;

        foreach (PermissionRegistry::names() as $name) {
            $permission = Permission::findOrCreate($name, 'web');
            $created += $permission->wasRecentlyCreated ? 1 : 0;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $created;
    }

    /**
     * Create missing built-in roles. With $reset, template permissions are re-applied.
     */
    public function syncRoleTemplates(bool $reset = false): void
    {
        foreach (PermissionRegistry::roleTemplates() as $name => $permissions) {
            $role = Role::findOrCreate($name, 'web');

            if ($reset || $role->wasRecentlyCreated) {
                $role->syncPermissions($permissions);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param  list<string>  $permissions
     */
    public function save(?Role $role, string $name, array $permissions): Role
    {
        return DB::transaction(function () use ($role, $name, $permissions) {
            $role ??= new Role(['guard_name' => 'web']);

            // Built-in role names are fixed.
            if (! $role->exists || RoleEnum::tryFrom($role->name) === null) {
                $role->name = $name;
            }

            $role->save();
            $role->syncPermissions(array_values(array_intersect($permissions, PermissionRegistry::names())));

            activity('permissions')->performedOn($role)->event('permissions_updated')
                ->withProperties(['permissions' => $permissions])
                ->log('Role permissions updated');

            $this->scope->flush();

            return $role;
        });
    }

    public static function label(Role|string $role): string
    {
        $name = $role instanceof Role ? $role->name : $role;

        return RoleEnum::tryFrom($name)?->getLabel() ?? str($name)->replace('_', ' ')->title()->toString();
    }
}
