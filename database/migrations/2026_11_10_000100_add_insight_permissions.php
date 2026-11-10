<?php

use App\Services\Permissions\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 6: who receives the morning summary and who may use Quick answers. On a database that
 * already has its roles, the built-in roles get the two permissions their template lists;
 * roles an admin made keep what they have.
 */
return new class extends Migration
{
    private const NEW = ['summary.receive', 'assistant.use'];

    public function up(): void
    {
        if (! DB::table('permissions')->exists()) {
            return;
        }

        foreach (self::NEW as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (PermissionRegistry::roleTemplates() as $roleName => $permissions) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            $give = array_values(array_intersect(self::NEW, $permissions));

            if ($role !== null && $give !== []) {
                $role->givePermissionTo($give);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', self::NEW)->get()->each(fn (Permission $p) => $p->delete());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
