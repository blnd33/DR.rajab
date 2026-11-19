<?php

use App\Services\Permissions\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * "companies.switch": open Al-Fadhaa' Bureau from KurdishMedicus with the button next to the
 * company name — no login of its own there (Super Admin has it through Gate::before).
 */
return new class extends Migration
{
    private const NEW = ['companies.switch'];

    public function up(): void
    {
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
