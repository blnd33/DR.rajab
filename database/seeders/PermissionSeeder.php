<?php

namespace Database\Seeders;

use App\Services\Permissions\RoleService;
use Illuminate\Database\Seeder;

/**
 * Creates every permission for every phase and the five role templates.
 * Safe to re-run on deploy: existing roles keep their customised permissions.
 */
class PermissionSeeder extends Seeder
{
    public function run(RoleService $roles): void
    {
        $roles->syncPermissionsFromRegistry();
        $roles->syncRoleTemplates();
    }
}
