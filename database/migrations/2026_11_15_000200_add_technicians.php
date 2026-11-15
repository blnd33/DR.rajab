<?php

use App\Enums\Role as RoleEnum;
use App\Enums\SurgeryDoctorRole;
use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Technicians: a role with no permissions (they never sign in), picked on surgeries in place
 * of assistants. Older surgeries keep their assistants.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Pg::isPostgres()) {
            DB::statement('ALTER TABLE surgery_doctors DROP CONSTRAINT IF EXISTS surgery_doctors_role_check');
        }

        Pg::enumCheck('surgery_doctors', 'role', SurgeryDoctorRole::values());

        if (! DB::table('permissions')->exists()) {
            return;
        }

        Permission::findOrCreate('technicians.manage', 'web');
        Role::findOrCreate(RoleEnum::Technician->value, 'web')->syncPermissions([]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'technicians.manage')->get()->each(fn (Permission $p) => $p->delete());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if (Pg::isPostgres()) {
            DB::statement('ALTER TABLE surgery_doctors DROP CONSTRAINT IF EXISTS surgery_doctors_role_check');
        }

        Pg::enumCheck('surgery_doctors', 'role', [SurgeryDoctorRole::Lead->value, SurgeryDoctorRole::Assistant->value]);
    }
};
