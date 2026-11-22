<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\Products\Pages\ListProducts;
use App\Models\Brand;
use App\Models\Product;
use App\Services\Catalog\SupplierCatalogService;
use App\Services\Settings\SettingsRepository;
use Database\Seeders\InventoryBaseSeeder;
use Livewire\Livewire;

/*
 * Items list split by catalog (client, 2026-11-22): "when I click Enovis Lima only Lima items
 * should come, and Trauma only trauma items". The supplier import files Lima items under
 * Arthroplasty (Hip / Knee / Shoulder) and Mikron items under Trauma.
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    app(SettingsRepository::class)->set('security.require_admin_mfa', false);
    $this->actingAs(userWithRole(Role::SuperAdmin));

    $catalog = app(SupplierCatalogService::class);
    $lima = Brand::query()->create(['name' => 'Lima (Enovis)']);
    $mikron = Brand::query()->create(['name' => 'Mikron']);

    $this->hip = Product::factory()->create(['sku' => '4250.20.080', 'brand_id' => $lima->id, 'category_id' => $catalog->category('HIP', 'Femoral Stems')->id]);
    $this->knee = Product::factory()->create(['sku' => '5422.15.150', 'brand_id' => $lima->id, 'category_id' => $catalog->category('KNEE', null)->id]);
    $this->plate = Product::factory()->create(['sku' => 'MX1051011544', 'brand_id' => $mikron->id, 'category_id' => $catalog->category('TRAUMA', 'Cranial Plates')->id]);
    $this->loose = Product::factory()->create(['sku' => 'NO-CAT-1']);
});

it('shows one tab per catalog, named by specialty and brand, with its count', function () {
    $tabs = Livewire::test(ListProducts::class)->instance()->getTabs();

    expect(array_keys($tabs))->toBe(['all', 'arthroplasty', 'trauma'])
        ->and($tabs['arthroplasty']->getLabel())->toBe('Arthroplasty · Lima (Enovis)')
        ->and($tabs['arthroplasty']->getBadge())->toBe('2')
        ->and($tabs['trauma']->getLabel())->toBe('Trauma · Mikron')
        ->and($tabs['trauma']->getBadge())->toBe('1');
});

it('lists only that catalog\'s items on its tab', function () {
    Livewire::test(ListProducts::class)
        ->assertCanSeeTableRecords([$this->hip, $this->knee, $this->plate, $this->loose])
        ->set('activeTab', 'arthroplasty')
        ->assertCanSeeTableRecords([$this->hip, $this->knee])
        ->assertCanNotSeeTableRecords([$this->plate, $this->loose])
        ->set('activeTab', 'trauma')
        ->assertCanSeeTableRecords([$this->plate])
        ->assertCanNotSeeTableRecords([$this->hip, $this->knee, $this->loose]);
});

it('opens the items list with tabs in every language', function (string $locale) {
    app()->setLocale($locale);

    $this->get(ListProducts::getUrl(panel: 'admin'))->assertOk()->assertSee('Lima (Enovis)')->assertSee('Mikron');
})->with(['en', 'ar', 'ckb']);
