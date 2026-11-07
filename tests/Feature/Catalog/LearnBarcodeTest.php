<?php

use App\Enums\BarcodeSymbology;
use App\Enums\Role;
use App\Livewire\Ops\Stock\Receive;
use App\Models\Activity;
use App\Models\Product;
use App\Services\Catalog\ProductService;
use Database\Seeders\InventoryBaseSeeder;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Learn on first scan: a supplier box barcode (GTIN) the system doesn't know is linked to
 * the product once, while receiving; after that the box scans by itself.
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    $this->staff = userWithRole(Role::BranchStaff, [branch('DHK')]);
    $this->stem = Product::factory()->create(['sku' => '4250.20.100', 'name' => ['en' => 'H-MAX S stem #10'], 'gtin' => null, 'tracking_type' => 'lot']);
});

// GS1-128 as the camera reads it: GTIN, expiry, lot.
const LIMA_BOX = ']C1010803339005183517280131'.'10LOT77';

it('asks which product an unknown box is, saves its barcode and adds the box with its lot', function () {
    $screen = Livewire::actingAs($this->staff)->test(Receive::class)
        ->call('handleScan', LIMA_BOX, 'camera')
        ->assertSet('unknown.gtin', '08033390051835')
        ->assertSee('which product is this?')
        ->set('linkSearch', '4250.20')
        ->assertSee('H-MAX S stem #10')
        ->call('linkProduct', $this->stem->id)
        ->assertSet('unknown', null);

    $line = collect($screen->get('lines'))->sole();

    expect($this->stem->fresh()->gtin)->toBe('08033390051835')
        ->and($line)->toMatchArray(['product_id' => $this->stem->id, 'lot' => 'LOT77', 'expiry_date' => '2028-01-31'])
        ->and(Activity::query()->where('event', 'barcode_learned')->where('subject_id', $this->stem->id)->exists())->toBeTrue();

    // Next box of the same item: no question, straight into the list.
    $screen->call('handleScan', ']C1010803339005183517280131'.'10LOT78', 'camera')
        ->assertSet('unknown', null);

    expect($screen->get('lines'))->toHaveCount(2);
});

it('keeps an existing GTIN and stores the new code as an extra barcode', function () {
    $this->stem->forceFill(['gtin' => '08712345678906'])->save();

    Livewire::actingAs($this->staff)->test(Receive::class)
        ->call('handleScan', LIMA_BOX, 'camera')
        ->call('linkProduct', $this->stem->id);

    expect($this->stem->fresh()->gtin)->toBe('08712345678906')
        ->and($this->stem->barcodes()->sole())->barcode->toBe('08033390051835')->symbology->toBe(BarcodeSymbology::Gtin);
});

it('does not ask people who may not link barcodes', function () {
    $this->staff->revokePermissionTo('products.link_barcodes');
    $this->staff->roles->each->revokePermissionTo('products.link_barcodes');

    Livewire::actingAs($this->staff->fresh())->test(Receive::class)
        ->call('handleScan', LIMA_BOX, 'camera')
        ->assertSet('unknown', null)
        ->assertDispatched('toast', type: 'error')
        ->call('linkProduct', $this->stem->id)
        ->assertForbidden();

    expect($this->stem->fresh()->gtin)->toBeNull();
});

it('refuses a barcode that already belongs to another product', function () {
    Product::factory()->create(['sku' => 'OTHER', 'gtin' => '08033390051835']);

    expect(fn () => app(ProductService::class)->learnBarcode($this->stem, '08033390051835', $this->staff))
        ->toThrow(ValidationException::class);
});
