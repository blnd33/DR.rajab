<?php

use App\Enums\Role as RoleEnum;
use App\Services\Permissions\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The company's manager: a built-in role with every permission. An account with this role and
 * "All branches" sees every branch, report and graph.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('permissions')->exists()) {
            return;
        }

        foreach (PermissionRegistry::names() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $role = Role::findOrCreate(RoleEnum::Manager->value, 'web');

        if ($role->wasRecentlyCreated) {
            $role->syncPermissions(PermissionRegistry::names());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::query()->where('name', RoleEnum::Manager->value)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
