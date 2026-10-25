<?php

use App\Enums\Role;
use App\Http\Controllers\LabelPrintController;
use App\Models\DataImport;
use App\Models\Product;
use App\Models\StockUnit;
use App\Services\Settings\SettingsRepository;
use Database\Seeders\DemoBranchSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\InventoryBaseSeeder;

/*
 * Every Phase 1 screen opens with demo data, in every language, for the right people.
 */

beforeEach(function () {
    $this->seed([InventoryBaseSeeder::class, DemoBranchSeeder::class]);
    app(SettingsRepository::class)->set('security.require_admin_mfa', false);
    $this->admin = userWithRole(Role::SuperAdmin, [branch('DHK')]);
    $this->actingAs($this->admin);
    $this->seed(DemoCatalogSeeder::class);
});

it('opens every inventory admin screen', function (string $locale) {
    $this->admin->forceFill(['locale' => $locale])->save();
    $this->actingAs($this->admin->fresh());

    foreach ([
        '/admin',
        '/admin/products',
        '/admin/products/create',
        '/admin/categories',
        '/admin/brands',
        '/admin/uoms',
        '/admin/suppliers',
        '/admin/stock-units',
        '/admin/stock-batches',
        '/admin/stock-movements',
        '/admin/data-imports',
        '/admin/data-imports/create',
        '/admin/receive-stock',
        '/admin/recall-search?lot=L',
        '/admin/low-stock-report',
        '/admin/expiry-levels',
        '/admin/label-templates',
        '/admin/unrecognized-scans',
    ] as $path) {
        expect($this->get($path)->status())->toBe(200, "{$path} in {$locale}");
    }
})->with(['en', 'ar', 'ckb']);

it('opens a product, a serial unit and a branch with its shelves', function () {
    $product = Product::query()->where('sku', 'KN-TIB-5')->firstOrFail();
    $unit = StockUnit::query()->firstOrFail();

    $this->get("/admin/products/{$product->id}/edit")->assertOk()->assertSee('KN-TIB-5');
    $this->get("/admin/stock-units/{$unit->id}")->assertOk()->assertSee($unit->serial);
    $this->get('/admin/branches/'.branch('DHK')->id.'/edit')->assertOk();
});

it('renders a label print sheet for units', function () {
    $ids = StockUnit::query()->limit(2)->pluck('id')->all();

    $this->get(LabelPrintController::url('unit', $ids, null, 2))
        ->assertOk()
        ->assertSee('<svg', false)
        ->assertSee('@page', false);
});

it('opens an import preview', function () {
    $import = DataImport::query()->create([
        'type' => 'products',
        'user_id' => $this->admin->id,
        'original_disk' => 'local',
        'original_path' => 'imports/none.xlsx',
        'original_filename' => 'none.xlsx',
        'status' => 'previewed',
    ]);

    $this->get("/admin/data-imports/{$import->id}")->assertOk()->assertSee('none.xlsx');
});

it('keeps stock screens away from users without stock permissions', function (string $path) {
    $this->actingAs(userWithRole(Role::Accountant, [], ['all_branches' => true]))
        ->get($path)
        ->assertForbidden();
})->with([
    '/admin/receive-stock',
    '/admin/expiry-levels',
    '/admin/label-templates',
    '/admin/unrecognized-scans',
]);

it('opens every stock screen in the ops app', function (string $locale) {
    $staff = userWithRole(Role::BranchStaff, [branch('DHK')], ['locale' => $locale]);
    $unit = StockUnit::query()->where('branch_id', branch('DHK')->id)->firstOrFail();
    $product = Product::query()->where('sku', 'SC-COR-3530')->firstOrFail();
    $this->actingAs($staff);

    foreach ([
        '/app',
        '/app/scan',
        '/app/stock',
        '/app/stock?level=1',
        '/app/stock?low=1',
        '/app/stock/products/{product}',
        '/app/stock/units/{unit}',
        '/app/stock/receive',
        '/app/recall?lot=LT',
    ] as $path) {
        $path = strtr($path, ['{unit}' => (string) $unit->id, '{product}' => (string) $product->id]);
        expect($this->get($path)->status())->toBe(200, "{$path} in {$locale}");
    }
})->with(['en', 'ar', 'ckb']);

it('hides a unit of another branch from branch staff', function () {
    $staff = userWithRole(Role::BranchStaff, [branch('BGW')]);
    $unit = StockUnit::query()->where('branch_id', branch('DHK')->id)->firstOrFail();

    $this->actingAs($staff)->get("/app/stock/units/{$unit->id}")->assertForbidden();
});

it('keeps receive stock away from a doctor', function () {
    $this->actingAs(userWithRole(Role::Doctor, [branch('DHK')]))->get('/app/stock/receive')->assertForbidden();
});
