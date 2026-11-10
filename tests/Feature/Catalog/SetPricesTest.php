<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\Products\Pages\ListProducts;
use App\Models\Product;
use App\Services\Catalog\ProductService;
use Database\Seeders\InventoryBaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
 * "Set price" on the products list: one price for many products, or a percentage up / down.
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    $this->dhk = branch('DHK');
    $this->admin = userWithRole(Role::SuperAdmin, [$this->dhk]);
    $this->service = app(ProductService::class);

    $this->priced = Product::factory()->create(['sku' => 'P-1', 'purchase_price' => '200', 'purchase_currency' => 'EUR', 'selling_price' => '300', 'selling_currency' => 'EUR']);
    $this->empty = Product::factory()->create(['sku' => 'P-2', 'purchase_price' => null, 'purchase_currency' => null, 'selling_price' => null, 'selling_currency' => null]);
});

it('sets one selling price on every product, with history', function () {
    $result = $this->service->setPrices([$this->priced->id, $this->empty->id], ['price' => 'selling', 'mode' => 'set', 'amount' => '1000', 'currency' => 'USD'], $this->admin);

    expect($result)->toBe(['changed' => 2, 'skipped' => 0])
        ->and($this->priced->fresh())->selling_price->toBe('1000.0000')->selling_currency->toBe('USD')->purchase_price->toBe('200.0000')
        ->and($this->empty->fresh())->selling_price->toBe('1000.0000')->selling_currency->toBe('USD')->purchase_price->toBeNull();

    $log = Activity::query()->where('subject_id', $this->priced->id)->where('event', 'updated')->latest('id')->first();
    expect($log->properties['old']['selling_price'])->toEqual('300.0000')
        ->and($log->properties['attributes']['selling_price'])->toEqual('1000.0000');

    // the same price again changes nothing
    expect($this->service->setPrices([$this->priced->id], ['price' => 'selling', 'mode' => 'set', 'amount' => '1000', 'currency' => 'USD'], $this->admin))
        ->toBe(['changed' => 0, 'skipped' => 1]);
});

it('fills only empty prices when asked, and sets both prices', function () {
    $result = $this->service->setPrices([$this->priced->id, $this->empty->id], ['price' => 'both', 'mode' => 'set', 'amount' => '٥٠٠', 'currency' => 'IQD', 'only_empty' => true], $this->admin);

    expect($result)->toBe(['changed' => 1, 'skipped' => 1])
        ->and($this->priced->fresh())->selling_price->toBe('300.0000')->selling_currency->toBe('EUR')
        ->and($this->empty->fresh())->selling_price->toBe('500.0000')->purchase_price->toBe('500.0000')->purchase_currency->toBe('IQD');
});

it('changes existing prices by a percentage and keeps the currency', function () {
    $result = $this->service->setPrices([$this->priced->id, $this->empty->id], ['price' => 'purchase', 'mode' => 'percent', 'percent' => '10'], $this->admin);

    expect($result)->toBe(['changed' => 1, 'skipped' => 1])
        ->and($this->priced->fresh())->purchase_price->toBe('220.0000')->purchase_currency->toBe('EUR')->selling_price->toBe('300.0000')
        ->and($this->empty->fresh()->purchase_price)->toBeNull();

    $this->service->setPrices([$this->priced->id], ['price' => 'selling', 'mode' => 'percent', 'percent' => '-12.5'], $this->admin);
    expect($this->priced->fresh()->selling_price)->toBe('262.5000');
});

it('refuses wrong input and people who may not change products', function (array $data) {
    expect(fn () => $this->service->setPrices([$this->priced->id], $data, $this->admin))->toThrow(ValidationException::class);
    expect($this->priced->fresh()->selling_price)->toBe('300.0000');
})->with([
    'no price chosen' => [['mode' => 'set', 'amount' => '1', 'currency' => 'USD']],
    'negative amount' => [['price' => 'selling', 'mode' => 'set', 'amount' => '-1', 'currency' => 'USD']],
    'unknown currency' => [['price' => 'selling', 'mode' => 'set', 'amount' => '1', 'currency' => 'GBP']],
    'not a number' => [['price' => 'selling', 'mode' => 'set', 'amount' => 'abc', 'currency' => 'USD']],
    'zero percent' => [['price' => 'selling', 'mode' => 'percent', 'percent' => '0']],
    'minus 100 percent' => [['price' => 'selling', 'mode' => 'percent', 'percent' => '-100']],
]);

it('is only for people who may change products', function () {
    $staff = userWithRole(Role::BranchStaff, [$this->dhk]);

    expect(fn () => $this->service->setPrices([$this->priced->id], ['price' => 'selling', 'mode' => 'set', 'amount' => '1', 'currency' => 'USD'], $staff))
        ->toThrow(AuthorizationException::class);
});

it('sets prices from the products list', function () {
    Livewire::actingAs($this->admin)->test(ListProducts::class)
        ->callTableBulkAction('setPrices', [$this->priced, $this->empty], data: ['price' => 'selling', 'mode' => 'set', 'amount' => '1000', 'currency' => 'USD'])
        ->assertHasNoTableBulkActionErrors()
        ->assertNotified('2 prices changed.');

    expect(Product::query()->where('selling_price', '1000')->count())->toBe(2);

    Livewire::actingAs($this->admin)->test(ListProducts::class)
        ->callTableBulkAction('setPrices', [$this->priced], data: ['price' => 'selling', 'mode' => 'percent', 'percent' => '5'])
        ->assertHasNoTableBulkActionErrors();

    expect($this->priced->fresh()->selling_price)->toBe('1050.0000');
});
