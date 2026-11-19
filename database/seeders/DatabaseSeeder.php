<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * What every site needs and nothing more (no branches, users, items or money):
     * currencies, permissions and roles, settings, expiry levels and labels, expense
     * categories. Idempotent.
     *
     * @var list<class-string<Seeder>>
     */
    public const BASE = [
        CurrencySeeder::class,
        PermissionSeeder::class,
        SettingsSeeder::class,
        InventoryBaseSeeder::class,
        ExpenseCategorySeeder::class,
    ];

    /**
     * Base data + demo data outside production. A test site (ORTHOTRACK_TEST_SITE=true)
     * never gets demo data: it starts empty, like the real site.
     */
    public function run(): void
    {
        $this->call(self::BASE);

        if (! app()->isProduction() && ! config('orthotrack.test_site')) {
            $this->call([
                DemoBranchSeeder::class,
                DemoUserSeeder::class,
                DemoCatalogSeeder::class,
                DemoMovementSeeder::class,
                DemoClinicalSeeder::class,
                DemoMoneySeeder::class,
                DemoAgentSeeder::class,
            ]);
        }
    }
}
