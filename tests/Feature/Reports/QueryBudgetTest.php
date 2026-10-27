<?php

use App\Enums\Role;
use App\Services\Reports\StockValueSnapshotter;
use App\Services\Settings\SettingsRepository;
use Database\Seeders\DemoBranchSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\DemoClinicalSeeder;
use Database\Seeders\DemoMoneySeeder;
use Database\Seeders\DemoMovementSeeder;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\InventoryBaseSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
 * Performance pass (P5-7): every main screen stays within a query budget with the demo data
 * (lazy loading is already forbidden outside production), so a new N+1 fails here.
 */
it('keeps every main screen within its query budget', function () {
    $this->seed([InventoryBaseSeeder::class, ExpenseCategorySeeder::class, DemoBranchSeeder::class]);
    app(SettingsRepository::class)->set('security.require_admin_mfa', false);
    $admin = userWithRole(Role::SuperAdmin, [branch('DHK')], ['username' => 'superadmin']);
    $this->actingAs($admin);
    $this->seed([DemoCatalogSeeder::class, DemoMovementSeeder::class, DemoClinicalSeeder::class, DemoMoneySeeder::class]);
    app(StockValueSnapshotter::class)->snapshot();

    $paths = ['/admin', '/admin/stock-units', '/admin/stock-batches', '/admin/products', '/admin/stock-movements', '/admin/transfers', '/admin/surgeries',
        '/admin/kits', '/admin/purchases', '/admin/purchases/1', '/admin/cash-boxes', '/admin/cash-boxes/1', '/admin/supplier-payments', '/admin/sales-invoices',
        '/admin/expenses', '/admin/approvals', '/admin/suppliers/1', '/admin/reports/stock-value', '/admin/reports/sales-profit', '/admin/reports/kits',
        '/app', '/app/stock', '/app/tasks', '/app/cash', '/app/surgeries', '/app/kits'];
    $out = [];

    foreach ($paths as $path) {
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });
        $this->get($path);
        $out[$path] = $count;
        DB::getEventDispatcher()?->forget(QueryExecuted::class);
    }

    foreach ($out as $path => $count) {
        expect($count)->toBeLessThan(60, "{$path} ran {$count} queries");
    }
});
