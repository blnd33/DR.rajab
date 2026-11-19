<?php

use App\Enums\Role;
use App\Enums\TrackingType;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\StockUnit;
use App\Services\Catalog\TestPricingService;
use App\Services\Money\FxService;
use Database\Seeders\InventoryBaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;

/*
 * Test prices for the test site: products with stock, in SKU order, cost 1, 2, 3… USD and sell
 * for the cost + 20 %; the pieces in stock get the same cost through the stock correction.
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    $this->dhk = branch('DHK');
    $this->admin = userWithRole(Role::SuperAdmin, [$this->dhk]);
    app(FxService::class)->setRate('USD', 'IQD', '1460', $this->admin);
    app(FxService::class)->setRate('EUR', 'USD', '1.10', $this->admin);

    $this->knee = Product::factory()->create(['sku' => 'KN-2', 'selling_price' => '900']);
    $this->hip = Product::factory()->create(['sku' => 'HP-1']);
    $this->screw = Product::factory()->create(['sku' => 'SC-9', 'tracking_type' => TrackingType::Lot, 'is_implant' => false]);
    $this->noStock = Product::factory()->create(['sku' => 'AA-0', 'purchase_price' => '555']);

    stockIn($this->dhk, $this->admin, [
        ['product_id' => $this->knee->id, 'serial' => 'K-1', 'unit_cost' => '350000', 'cost_currency' => 'IQD'],
        ['product_id' => $this->knee->id, 'serial' => 'K-2'],
        ['product_id' => $this->hip->id, 'serial' => 'H-1', 'unit_cost' => '80', 'cost_currency' => 'EUR'],
        ['product_id' => $this->screw->id, 'lot' => 'L-1', 'quantity' => 30],
    ]);
});

it('numbers the products in stock by SKU and adds 20 % for the selling price', function () {
    $plan = app(TestPricingService::class)->plan();

    expect($plan->map(fn ($r) => [$r['product']->sku, $r['cost'], $r['selling']])->all())->toBe([
        ['HP-1', '1.0000', '1.2000'],
        ['KN-2', '2.0000', '2.4000'],
        ['SC-9', '3.0000', '3.6000'],
    ]);
});

it('sets the product prices and the cost of every piece in stock, on the ledger', function () {
    $summary = app(TestPricingService::class)->apply($this->admin);

    expect($summary)->toMatchArray(['products' => 3, 'units' => 3, 'lots' => 1, 'skipped' => 0, 'last_cost' => '3.0000'])
        ->and($this->knee->fresh()->only(['purchase_price', 'purchase_currency', 'selling_price', 'selling_currency']))
        ->toBe(['purchase_price' => '2.0000', 'purchase_currency' => 'USD', 'selling_price' => '2.4000', 'selling_currency' => 'USD'])
        ->and(StockUnit::query()->where('product_id', $this->knee->id)->pluck('unit_cost')->unique()->values()->all())->toBe(['2.0000'])
        ->and(StockUnit::query()->where('serial', 'H-1')->value('cost_currency'))->toBe('USD')
        ->and(StockBatch::query()->where('product_id', $this->screw->id)->where('quantity', '>', 0)->get(['quantity', 'unit_cost'])->toArray())
        ->toBe([['quantity' => 30, 'unit_cost' => '3.0000']])
        // A product without stock keeps its price.
        ->and($this->noStock->fresh()->purchase_price)->toBe('555.0000')
        ->and(StockMovement::query()->where('reason', TestPricingService::REASON)->count())->toBe(4);
});

it('starts and steps where asked, and only for someone who may correct stock and edit products', function () {
    $plan = app(TestPricingService::class)->plan(start: 10, step: 5, markupPercent: '25');

    expect($plan->map(fn ($r) => [$r['cost'], $r['selling']])->all())->toBe([['10.0000', '12.5000'], ['15.0000', '18.7500'], ['20.0000', '25.0000']])
        ->and(fn () => app(TestPricingService::class)->apply(userWithRole(Role::Doctor, [$this->dhk])))->toThrow(AuthorizationException::class);
});

it('runs from the command line with a preview first', function () {
    $this->artisan('prices:test-fill', ['--dry-run' => true])
        ->expectsOutputToContain('3 products with stock; the last (SC-9) costs 3.00 USD and sells for 3.60 USD.')
        ->expectsOutputToContain('Dry run: nothing was changed.')
        ->assertSuccessful();

    expect($this->knee->fresh()->purchase_price)->not->toBe('2.0000');

    $this->artisan('prices:test-fill', ['--user' => $this->admin->username])
        ->expectsConfirmation("Change these prices and the cost of the pieces in stock, as {$this->admin->username}?", 'yes')
        ->expectsOutputToContain('Done: 3 products priced, 3 serial pieces and 1 lots re-costed.')
        ->assertSuccessful();

    expect($this->knee->fresh()->purchase_price)->toBe('2.0000');
});
