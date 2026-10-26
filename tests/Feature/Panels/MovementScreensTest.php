<?php

use App\Enums\Role;
use App\Models\CustomerReturn;
use App\Models\StockCount;
use App\Models\StockRequest;
use App\Models\SupplierReturn;
use App\Models\Transfer;
use App\Models\WriteOff;
use App\Services\Settings\SettingsRepository;
use Database\Seeders\DemoBranchSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\DemoMovementSeeder;
use Database\Seeders\InventoryBaseSeeder;

/*
 * Phase 2 screens open in every language, with the demo documents in every state.
 */

beforeEach(function () {
    $this->seed([InventoryBaseSeeder::class, DemoBranchSeeder::class]);
    app(SettingsRepository::class)->set('security.require_admin_mfa', false);
    $this->admin = userWithRole(Role::SuperAdmin, [branch('DHK')]);
    $this->actingAs($this->admin);
    $this->seed([DemoCatalogSeeder::class, DemoMovementSeeder::class]);
});

it('opens every movement admin screen', function (string $locale) {
    $this->admin->forceFill(['locale' => $locale])->save();
    $this->actingAs($this->admin->fresh());

    $paths = [
        '/admin',
        '/admin/customers',
        '/admin/issues',
        '/admin/transfers',
        '/admin/transfers/create',
        '/admin/stock-requests',
        '/admin/stock-requests/create',
        '/admin/stock-requests/create?branch='.branch('BGW')->id.'&low=1',
        '/admin/write-offs',
        '/admin/write-offs/create',
        '/admin/customer-returns',
        '/admin/customer-returns/create',
        '/admin/supplier-returns',
        '/admin/supplier-returns/create',
        '/admin/stock-counts',
        '/admin/stock-counts/create',
        '/admin/low-stock-report',
        '/admin/branches',
    ];

    foreach (Transfer::all() as $transfer) {
        $paths[] = "/admin/transfers/{$transfer->id}";
    }

    foreach ([
        'stock-requests' => StockRequest::class,
        'write-offs' => WriteOff::class,
        'customer-returns' => CustomerReturn::class,
        'supplier-returns' => SupplierReturn::class,
        'stock-counts' => StockCount::class,
    ] as $slug => $model) {
        foreach ($model::all() as $record) {
            $paths[] = "/admin/{$slug}/{$record->id}";
        }
    }

    foreach ($paths as $path) {
        expect($this->get($path)->status())->toBe(200, "{$path} in {$locale}");
    }
})->with(['en', 'ar', 'ckb']);

it('opens every movement screen in the ops app', function (string $locale) {
    $staff = userWithRole(Role::BranchStaff, [branch('DHK')], ['locale' => $locale]);
    $this->actingAs($staff);

    $paths = ['/app', '/app/tasks', '/app/transfers/new', '/app/requests/new', '/app/requests/new?low=1', '/app/counts/new', '/app/write-offs/new', '/app/returns/new'];

    foreach (Transfer::query()->where('from_branch_id', branch('DHK')->id)->orWhere('to_branch_id', branch('DHK')->id)->get() as $transfer) {
        $paths[] = "/app/transfers/{$transfer->id}";
    }

    foreach (StockCount::query()->where('branch_id', branch('DHK')->id)->get() as $count) {
        $paths[] = "/app/counts/{$count->id}";
    }

    foreach ($paths as $path) {
        expect($this->get($path)->status())->toBe(200, "{$path} in {$locale}");
    }

    $this->actingAs($this->admin);
    $this->get('/app/requests/'.StockRequest::query()->firstOrFail()->id)->assertOk();
})->with(['en', 'ar', 'ckb']);

it('keeps documents of other branches away from branch staff', function () {
    $staff = userWithRole(Role::BranchStaff, [branch('BGW')]);
    $this->actingAs($staff);

    $draft = Transfer::query()->where('from_branch_id', branch('DHK')->id)->where('to_branch_id', branch('EBL')->id)->firstOrFail();
    $count = StockCount::query()->where('branch_id', branch('DHK')->id)->firstOrFail();

    expect($this->get("/app/transfers/{$draft->id}")->status())->toBe(403)
        ->and($this->get("/app/counts/{$count->id}")->status())->toBe(403)
        ->and($this->get('/admin/issues')->status())->not->toBe(200);
});
