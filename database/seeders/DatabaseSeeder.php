<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Base data (always safe to run, idempotent) + demo data outside production.
     */
    public function run(): void
    {
        $this->call([
            CurrencySeeder::class,
            PermissionSeeder::class,
            SettingsSeeder::class,
            InventoryBaseSeeder::class,
        ]);

        if (! app()->isProduction()) {
            $this->call([
                DemoBranchSeeder::class,
                DemoUserSeeder::class,
                DemoCatalogSeeder::class,
                DemoMovementSeeder::class,
                DemoClinicalSeeder::class,
            ]);
        }
    }
}
