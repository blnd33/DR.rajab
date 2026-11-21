<?php

use App\Enums\Role;
use App\Enums\TrackingType;
use App\Enums\WriteOffReason;
use App\Models\Product;
use App\Models\StockBatch;
use App\Services\Money\FxService;
use App\Services\Movement\WriteOffService;
use App\Services\Reports\InactiveStockReport;
use App\Services\Reports\InactiveStockValueReport;
use App\Services\Reports\ReportContext;
use App\Services\Settings\SettingsRepository;
use Database\Seeders\InventoryBaseSeeder;

/*
 * Inactive Stock Value / Inactive Stock Volume (two sections of the side menu): stock on hand in a
 * station that has not been used there for the chosen number of days, although it has been there
 * that long.
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

    $this->plate = Product::factory()->create(['sku' => 'PL-1', 'tracking_type' => TrackingType::Lot]);
    $this->screw = Product::factory()->create(['sku' => 'SC-1', 'tracking_type' => TrackingType::Lot]);

    // Day 0: plates in Duhok and Erbil.
    stockIn($this->dhk, $this->admin, [['product_id' => $this->plate->id, 'lot' => 'L-1', 'quantity' => 30, 'unit_cost' => '10', 'cost_currency' => 'USD']]);
    stockIn($this->ebl, $this->admin, [['product_id' => $this->plate->id, 'lot' => 'L-2', 'quantity' => 5, 'unit_cost' => '10', 'cost_currency' => 'USD']]);
});

function inactiveRows(string $class, array $input = []): array
{
    $report = app($class);

    return collect($report->rows(ReportContext::make($report, test()->admin->fresh(), $input)))->sortBy('key')->values()->all();
}

it('lists stock that has not been used for the chosen days, per item and station', function () {
    // Nothing is inactive yet: it only just arrived.
    expect(inactiveRows(InactiveStockReport::class))->toBe([]);

    $this->travel(100)->days();
    // Day 95 → 100: screws arrive in Duhok; too new to be inactive.
    stockIn($this->dhk, $this->admin, [['product_id' => $this->screw->id, 'lot' => 'S-1', 'quantity' => 50, 'unit_cost' => '2', 'cost_currency' => 'USD']]);

    $rows = inactiveRows(InactiveStockReport::class);
    expect(collect($rows)->map(fn ($r) => [$r['sku'], $r['quantity'], $r['last_used'], $r['idle_days']])->all())
        ->toBe([['PL-1', 30, 'Never', 100], ['PL-1', 5, 'Never', 100]]);

    // Used in Duhok today: Duhok's plates are active again, Erbil's are not.
    $writeOffs = app(WriteOffService::class);
    $writeOffs->approve($writeOffs->report($this->dhk, WriteOffReason::Damaged, [['stock_batch_id' => StockBatch::query()->where('lot', 'L-1')->value('id'), 'quantity' => 1]], $this->admin, 'Dropped'), $this->admin);

    $rows = inactiveRows(InactiveStockReport::class);
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['branch'])->toBe((string) $this->ebl->name)
        ->and($rows[0]['quantity'])->toBe(5);

    // A longer period: nothing has been there 180 days.
    expect(inactiveRows(InactiveStockReport::class, ['idle' => '180']))->toBe([]);
});

it('shows the value at true cost on its own screen, per item, station or specialty', function () {
    $this->travel(100)->days();

    $rows = inactiveRows(InactiveStockValueReport::class, ['group' => 'item']);
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['sku'])->toBe('PL-1')
        ->and($rows[0]['stations'])->toBe(2)
        ->and($rows[0]['quantity'])->toBe(35)
        ->and($rows[0]['value'])->toBe('350.00')
        ->and(collect(inactiveRows(InactiveStockValueReport::class, ['group' => 'branch']))->sum('quantity'))->toBe(35)
        ->and(collect(app(InactiveStockReport::class)->columns(ReportContext::make(app(InactiveStockReport::class), $this->admin, [])))->pluck('name'))->not->toContain('value');
});

it('has two sections of its own in the side menu, value first', function () {
    $this->get('/admin')->assertOk()
        ->assertSeeInOrder(['Stock', 'Inactive stock value', 'Inactive stock value', 'Inactive stock volume', 'Inactive stock volume', 'Pricelist']);

    $this->get('/admin/reports/inactive-stock-value')->assertOk()->assertSee('Not used for');
    $this->get('/admin/reports/inactive-stock-volume')->assertOk();
    $this->get(route('reports.print', ['report' => 'inactive-stock-value', 'idle' => '180']))->assertOk();
});
