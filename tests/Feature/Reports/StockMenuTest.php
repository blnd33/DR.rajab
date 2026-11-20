<?php

use App\Enums\Governorate;
use App\Enums\Role;
use App\Enums\Specialty;
use App\Enums\TrackingType;
use App\Enums\WriteOffReason;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockBatch;
use App\Services\Money\FxService;
use App\Services\Movement\WriteOffService;
use App\Services\Reports\GroupStockReport;
use App\Services\Reports\InventoryReport;
use App\Services\Reports\PriceListReport;
use App\Services\Reports\ReportContext;
use App\Services\Reports\StockInputReport;
use App\Services\Reports\StockOutputReport;
use App\Services\Reports\StockValueByItemReport;
use App\Services\Settings\SettingsRepository;
use Database\Seeders\InventoryBaseSeeder;

/*
 * The Stock menu as the client listed it: Inventory / stock volume, Stock value, Stock per
 * Specialty > Category > Subcategory / Items Group > Item, Stock value per item / group, Stock
 * movement, Input, Output, Items list — then the older Stock screens. Pricelist and Catalogues are
 * two sections of their own under it.
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    app(SettingsRepository::class)->set('security.require_admin_mfa', false);
    $this->dhk = branch('DHK');
    $this->ebl = branch('EBL');
    $this->admin = userWithRole(Role::SuperAdmin, [$this->dhk], ['all_branches' => true]);
    $this->actingAs($this->admin);
    app(FxService::class)->setRate('USD', 'IQD', '1460', $this->admin);
    app(FxService::class)->setRate('EUR', 'USD', '1.10', $this->admin);

    // Specialty Trauma: category "Trauma" with subcategory "Plates". Specialty Spine: category "Spine".
    $trauma = Category::query()->create(['code' => 'TRAUMA', 'name' => ['en' => 'Trauma'], 'specialty' => Specialty::Trauma, 'is_active' => true]);
    $plates = Category::query()->create(['code' => 'PLATES', 'parent_id' => $trauma->id, 'name' => ['en' => 'Plates'], 'is_active' => true]);
    $spine = Category::query()->create(['code' => 'SPINE', 'name' => ['en' => 'Spine Cages'], 'specialty' => Specialty::Spine, 'is_active' => true]);

    $this->plate = Product::factory()->create(['sku' => 'PL-1', 'category_id' => $plates->id, 'tracking_type' => TrackingType::Lot, 'purchase_price' => '10', 'purchase_currency' => 'USD', 'selling_price' => '12', 'selling_currency' => 'USD']);
    $this->cage = Product::factory()->create(['sku' => 'SP-1', 'category_id' => $spine->id, 'purchase_price' => '100', 'purchase_currency' => 'USD', 'selling_price' => null]);

    stockIn($this->dhk, $this->admin, [['product_id' => $this->plate->id, 'lot' => 'L-1', 'quantity' => 30, 'unit_cost' => '10', 'cost_currency' => 'USD']]);
    stockIn($this->ebl, $this->admin, [
        ['product_id' => $this->plate->id, 'lot' => 'L-2', 'quantity' => 5, 'unit_cost' => '10', 'cost_currency' => 'USD'],
        ['product_id' => $this->cage->id, 'serial' => 'C-1', 'unit_cost' => '100', 'cost_currency' => 'USD'],
        ['product_id' => $this->cage->id, 'serial' => 'C-2', 'unit_cost' => '100', 'cost_currency' => 'USD'],
    ]);
});

function stockReport(string $class, array $input = []): array
{
    $report = app($class);

    return collect($report->rows(ReportContext::make($report, test()->admin, $input + ['from' => now()->subYear()->toDateString()])))
        ->sortBy('key')->values()->all();
}

it('shows the whole stock: Iraq, Kurdistan, and Iraq including Kurdistan', function () {
    // DHK in Duhok (Kurdistan), EBL here in Baghdad (Iraq).
    $this->dhk->forceFill(['governorate' => Governorate::Duhok])->save();
    $this->ebl->forceFill(['governorate' => Governorate::Baghdad])->save();

    // The first view: the three totals.
    $regions = app(InventoryReport::class)->rows(ReportContext::make(app(InventoryReport::class), $this->admin, []));
    expect(collect($regions)->map(fn ($r) => [$r['region'], $r['stations'], $r['items'], $r['quantity']])->all())->toBe([
        ['Iraq', 1, 2, 7],
        ['Kurdistan', 1, 1, 30],
        ['Iraq and Kurdistan', 2, 2, 37],
    ]);

    // Per item: Iraq | Kurdistan | the whole.
    expect(collect(stockReport(InventoryReport::class, ['group' => 'item']))->map(fn ($r) => [$r['sku'], $r['iraq'], $r['kurdistan'], $r['whole']])->all())
        ->toBe([['PL-1', 5, 30, 35], ['SP-1', 2, 0, 2]]);

    // Per governorate (every governorate, with or without stock) and per station.
    $governorates = collect(stockReport(InventoryReport::class, ['group' => 'governorate']))->pluck('quantity', 'key');
    expect($governorates)->toHaveCount(19)
        ->and($governorates['duhok'])->toBe(30)
        ->and($governorates['baghdad'])->toBe(7)
        ->and($governorates['basra'])->toBe(0)
        ->and(collect(stockReport(InventoryReport::class, ['group' => 'branch']))->sum('quantity'))->toBe(37);
});

it('adds stock up per specialty > category > subcategory > item, with the value per item', function () {
    expect(collect(stockReport(GroupStockReport::class))->pluck('quantity', 'specialty')->sortKeys()->all())->toBe(['Spine' => 2, 'Trauma' => 35])
        ->and(collect(stockReport(GroupStockReport::class, ['group' => 'category']))->map(fn ($r) => [$r['specialty'], $r['category'], $r['quantity']])->sortBy(0)->values()->all())
        ->toBe([['Spine', 'Spine Cages', 2], ['Trauma', 'Trauma', 35]])
        ->and(collect(stockReport(GroupStockReport::class, ['group' => 'subcategory']))->map(fn ($r) => [$r['specialty'], $r['category'], $r['subcategory'], $r['quantity']])->sortBy(0)->values()->all())
        ->toBe([['Spine', 'Spine Cages', '—', 2], ['Trauma', 'Trauma', 'Plates', 35]])
        ->and(collect(stockReport(GroupStockReport::class, ['group' => 'item']))->pluck('quantity', 'sku')->sortKeys()->all())->toBe(['PL-1' => 35, 'SP-1' => 2]);

    $values = collect(stockReport(StockValueByItemReport::class))->pluck('value', 'sku')->sortKeys()->all();
    expect($values)->toBe(['PL-1' => '350.00', 'SP-1' => '200.00'])
        ->and(collect(stockReport(StockValueByItemReport::class, ['group' => 'specialty']))->pluck('value', 'specialty')->sortKeys()->all())->toBe(['Spine' => '200.00', 'Trauma' => '350.00']);
});

it('splits movements into what came in and what went out', function () {
    expect(collect(stockReport(StockInputReport::class))->sum('quantity'))->toBe(37)
        ->and(stockReport(StockOutputReport::class))->toBe([]);

    $writeOffs = app(WriteOffService::class);
    $batch = StockBatch::query()->where('lot', 'L-1')->firstOrFail();
    $writeOffs->approve($writeOffs->report($this->dhk, WriteOffReason::Damaged, [['stock_batch_id' => $batch->id, 'quantity' => 4]], $this->admin, 'Dropped'), $this->admin);

    expect(collect(stockReport(StockOutputReport::class))->sum('quantity'))->toBe(4)
        ->and(collect(stockReport(StockInputReport::class))->sum('quantity'))->toBe(37);
});

it('lists prices with the profit, priced items first and all on request', function () {
    $rows = stockReport(PriceListReport::class);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['sku'])->toBe('PL-1')
        ->and($rows[0]['cost'])->toBe('10.00')
        ->and($rows[0]['selling'])->toBe('12.00')
        ->and($rows[0]['margin'])->toBe('20.0')
        ->and(collect(stockReport(PriceListReport::class, ['group' => 'all']))->pluck('sku')->all())->toBe(['PL-1', 'SP-1']);
});

it('puts the client\'s list first in the Stock menu, then Pricelist and Catalogues as two sections', function () {
    $html = $this->get('/admin')->assertOk()->getContent();

    $order = ['Inventory / stock volume', 'Stock value', 'Stock Volume per Specialty &gt; Category &gt; Subcategory / Items Group &gt; Item', 'Stock Value per Specialty &gt; Category &gt; Subcategory / Items Group &gt; Item', 'Stock movement', 'Input', 'Output', 'Items list', 'Receive stock', 'Pricelist', 'Catalogues', 'Lima', 'Mikron', 'Covision'];
    $this->get('/admin')->assertSeeInOrder($order, false);

    // Off the menu.
    expect($html)->not->toContain('Stock movement totals');

    // Every word of the menus starts with a capital letter.
    expect($html)->toContain('text-transform: capitalize');

    foreach (['/admin/reports/inventory', '/admin/reports/group-stock', '/admin/reports/stock-value-items', '/admin/reports/stock-input', '/admin/reports/stock-output', '/admin/reports/price-list', '/admin/reports/stock-movements', '/admin/products'] as $url) {
        $this->get($url)->assertOk();
    }
});
