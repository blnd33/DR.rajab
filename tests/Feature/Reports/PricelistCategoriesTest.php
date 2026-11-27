<?php

use App\Enums\Role;
use App\Enums\Specialty;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\Reports\PriceListReport;
use App\Services\Reports\ReportContext;
use App\Services\Settings\SettingsRepository;
use Database\Seeders\InventoryBaseSeeder;

/*
 * Pricelist by category: Pricelist → Arthroplasty / Trauma / Spine / Sport medicine → the companies
 * → their price list (name and price of each item); and All companies, every item.
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    app(SettingsRepository::class)->set('security.require_admin_mfa', false);
    $this->admin = userWithRole(Role::SuperAdmin, [branch('DHK')]);

    $trauma = Category::query()->create(['code' => 'TRAUMA', 'name' => ['en' => 'Trauma'], 'specialty' => Specialty::Trauma, 'is_active' => true]);
    $plates = Category::query()->create(['code' => 'PLATES', 'parent_id' => $trauma->id, 'name' => ['en' => 'Plates'], 'is_active' => true]);
    $knees = Category::query()->create(['code' => 'KNEES', 'name' => ['en' => 'Knees'], 'specialty' => Specialty::Arthroplasty, 'is_active' => true]);

    $this->mikron = Brand::query()->create(['name' => 'Mikron', 'is_active' => true]);
    $this->lima = Brand::query()->create(['name' => 'Lima', 'is_active' => true]);

    $price = ['purchase_price' => '10', 'purchase_currency' => 'USD', 'selling_price' => '12', 'selling_currency' => 'USD'];
    Product::factory()->create(['sku' => 'MX-1', 'name' => ['en' => 'Mikron plate'], 'category_id' => $plates->id, 'brand_id' => $this->mikron->id] + $price);
    Product::factory()->create(['sku' => 'MX-2', 'name' => ['en' => 'Mikron screw'], 'category_id' => $plates->id, 'brand_id' => $this->mikron->id, 'selling_price' => null]);
    Product::factory()->create(['sku' => 'NB-1', 'name' => ['en' => 'Plate without brand'], 'category_id' => $plates->id, 'brand_id' => null] + $price);
    Product::factory()->create(['sku' => 'LI-1', 'name' => ['en' => 'Lima knee'], 'category_id' => $knees->id, 'brand_id' => $this->lima->id] + $price);
});

function priceRows(array $input): array
{
    $report = app(PriceListReport::class);

    return collect($report->rows(ReportContext::make($report, test()->admin, $input)))->pluck('company', 'sku')->sortKeys()->all();
}

it('narrows the price list to a category and a company', function () {
    expect(priceRows([]))->toBe(['LI-1' => 'Lima', 'MX-1' => 'Mikron', 'NB-1' => '—'])
        ->and(priceRows(['specialty' => 'trauma']))->toBe(['MX-1' => 'Mikron', 'NB-1' => '—'])
        ->and(priceRows(['specialty' => 'trauma', 'brand' => $this->mikron->id]))->toBe(['MX-1' => 'Mikron'])
        ->and(priceRows(['specialty' => 'trauma', 'brand' => '0']))->toBe(['NB-1' => '—'])
        ->and(priceRows(['specialty' => 'trauma', 'brand' => $this->mikron->id, 'group' => 'all']))->toBe(['MX-1' => 'Mikron', 'MX-2' => 'Mikron'])
        ->and(priceRows(['specialty' => 'spine']))->toBe([]);

    // Printing keeps the choice.
    expect(ReportContext::make(app(PriceListReport::class), $this->admin, ['specialty' => 'trauma', 'brand' => $this->mikron->id])->toQuery())
        ->toMatchArray(['specialty' => 'trauma', 'brand' => (string) $this->mikron->id]);
});

it('opens Pricelist on the categories, a category on its companies, a company on its prices', function () {
    $this->actingAs($this->admin);

    $this->get('/admin/section?section=pricelist')->assertOk()
        ->assertSeeInOrder(['data-section-windows', 'Arthroplasty', 'Trauma', 'Spine', 'Sport medicine', 'All companies'], false);

    $this->get('/admin/pricelist?specialty=trauma')->assertOk()
        ->assertSeeInOrder(['data-pricelist-companies', 'Mikron', '2 items', '1 with a price', 'Without company', 'All companies'], false)
        ->assertDontSee('Lima')
        ->assertSee('specialty=trauma&amp;brand='.$this->mikron->id, false);

    $this->get('/admin/reports/price-list?specialty=trauma&brand='.$this->mikron->id)->assertOk()
        ->assertSee('Mikron plate')->assertDontSee('Lima knee')->assertDontSee('Plate without brand');

    $this->get('/admin/reports/price-list')->assertOk()->assertSee('All companies')->assertSee('Lima knee')->assertSee('Mikron plate');
    $this->get('/admin/pricelist?specialty=spine')->assertOk()->assertSee('No company has items in this category yet.');
    $this->get('/admin/pricelist?specialty=nonsense')->assertNotFound();
    $this->get(route('reports.print', ['report' => 'price-list', 'specialty' => 'trauma', 'brand' => $this->mikron->id]))->assertOk()->assertSee('Mikron plate')->assertDontSee('Lima knee');
});

it('keeps the price list for the people who may see profit', function () {
    $this->actingAs(userWithRole(Role::BranchStaff, [branch('DHK')]));

    // Not in the admin at all (sent to the phone app) or not allowed: either way, no prices.
    expect($this->get('/admin/pricelist?specialty=trauma')->status())->toBeIn([302, 403]);
});
