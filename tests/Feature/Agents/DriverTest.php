<?php

use App\Enums\PlateKind;
use App\Enums\PlateRegion;
use App\Enums\Role;
use App\Filament\Admin\Resources\Drivers\Pages\ListDrivers;
use App\Filament\Admin\Resources\Drivers\Pages\ViewDriver;
use App\Filament\Admin\Resources\Drivers\RelationManagers\DriverTripsRelationManager;
use App\Models\Driver;
use App\Services\Agents\DriverService;
use App\Services\Money\FxService;
use App\Services\Settings\SettingsRepository;
use App\Support\Plate;
use Database\Seeders\InventoryBaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Drivers: the car with its Iraqi plate (English "24 A 3003" or Arabic "12345 دهوك"), and the
 * trips with arrival date, received date and what we paid (IQD, USD or EUR).
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    app(SettingsRepository::class)->set('security.require_admin_mfa', false);
    $this->admin = userWithRole(Role::SuperAdmin, [branch('DHK')]);
    app(FxService::class)->setRate('USD', 'IQD', '1460', $this->admin);
    app(FxService::class)->setRate('EUR', 'USD', '1.10', $this->admin);
    $this->drivers = app(DriverService::class);
});

it('stores and shows both kinds of Iraqi plate', function () {
    $english = $this->drivers->create(['name' => 'Sherwan', 'phone' => '0750 222 3344', 'car_type' => 'Toyota Hilux', 'company_car' => true,
        'plate_kind' => 'english', 'plate_code' => '24', 'plate_letter' => 'a', 'plate_number' => ' 3003 ', 'plate_region' => 'duhok'], $this->admin);
    // Arabic-Indic digits are kept as Western digits.
    $arabic = $this->drivers->create(['name' => 'Hawre', 'plate_kind' => 'arabic', 'plate_number' => '١٢٣٤٥', 'plate_region' => 'duhok',
        'plate_code' => '22', 'plate_letter' => 'B'], $this->admin);

    expect($english->only(['plate_code', 'plate_letter', 'plate_number', 'company_car']))
        ->toBe(['plate_code' => '24', 'plate_letter' => 'A', 'plate_number' => '3003', 'company_car' => true])
        ->and($english->plate_region)->toBeNull()
        ->and($english->plate)->toBe('24 A 3003')
        ->and($arabic->plate_kind)->toBe(PlateKind::Arabic)
        ->and($arabic->plate_region)->toBe(PlateRegion::Duhok)
        ->and($arabic->only(['plate_code', 'plate_letter', 'plate_number']))->toBe(['plate_code' => null, 'plate_letter' => null, 'plate_number' => '12345'])
        ->and($arabic->plate)->toBe('12345 دهوك')
        ->and(Plate::format(PlateKind::Arabic, null, null, '777', PlateRegion::Iraq))->toBe('777 العراق');
});

it('refuses plates that are not written the Iraqi way', function (array $plate, string $field) {
    try {
        $this->drivers->create(['name' => 'Driver'] + $plate, $this->admin);
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($field);
    }
})->with([
    'no plate type' => [['plate_number' => '3003'], 'plate_kind'],
    'code of 3 digits' => [['plate_kind' => 'english', 'plate_code' => '224', 'plate_letter' => 'A', 'plate_number' => '3003'], 'plate_code'],
    'two letters' => [['plate_kind' => 'english', 'plate_code' => '24', 'plate_letter' => 'AB', 'plate_number' => '3003'], 'plate_letter'],
    'Arabic letter on an English plate' => [['plate_kind' => 'english', 'plate_code' => '24', 'plate_letter' => 'ب', 'plate_number' => '3003'], 'plate_letter'],
    'number of 7 digits' => [['plate_kind' => 'english', 'plate_code' => '24', 'plate_letter' => 'A', 'plate_number' => '1234567'], 'plate_number'],
    'letters in the number' => [['plate_kind' => 'arabic', 'plate_number' => '12A45', 'plate_region' => 'erbil'], 'plate_number'],
    'Arabic plate without the city' => [['plate_kind' => 'arabic', 'plate_number' => '12345'], 'plate_region'],
]);

it('records trips with their dates and pay, and totals them per currency', function () {
    $driver = $this->drivers->create(['name' => 'Sherwan', 'plate_kind' => 'english', 'plate_code' => '24', 'plate_letter' => 'A', 'plate_number' => '3003'], $this->admin);

    $first = $this->drivers->addTrip($driver, ['arrived_on' => '2026-11-10', 'received_on' => '2026-11-11', 'amount' => '150000', 'currency' => 'IQD'], $this->admin);
    $second = $this->drivers->addTrip($driver, ['arrived_on' => '2026-11-15', 'amount' => '100,000', 'currency' => 'IQD', 'notes' => 'From Erbil'], $this->admin);
    $this->drivers->addTrip($driver, ['arrived_on' => '2026-11-16', 'amount' => '80', 'currency' => 'USD'], $this->admin);

    expect($first->fx_snapshot_id)->not->toBeNull()
        ->and($second->received_on)->toBeNull()
        ->and($this->drivers->paidTotals($driver))->toBe(['IQD 250,000', 'USD 80.00']);

    $this->drivers->markReceived($second, '2026-11-17', $this->admin);
    expect($second->fresh()->received_on->toDateString())->toBe('2026-11-17');

    expect(fn () => $this->drivers->addTrip($driver, ['arrived_on' => '2026-11-20', 'received_on' => '2026-11-19', 'amount' => '1', 'currency' => 'USD'], $this->admin))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->drivers->addTrip($driver, ['arrived_on' => '2026-11-20', 'amount' => '1', 'currency' => 'GBP'], $this->admin))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->drivers->addTrip($driver, ['arrived_on' => '2026-11-20', 'amount' => '-1', 'currency' => 'USD'], $this->admin))
        ->toThrow(ValidationException::class);
});

it('voids a wrong trip with a reason instead of deleting it', function () {
    $driver = $this->drivers->create(['name' => 'Sherwan', 'plate_kind' => 'arabic', 'plate_number' => '4455', 'plate_region' => 'erbil'], $this->admin);
    $trip = $this->drivers->addTrip($driver, ['arrived_on' => '2026-11-10', 'amount' => '500', 'currency' => 'EUR'], $this->admin);

    expect(fn () => $this->drivers->voidTrip($trip, '  ', $this->admin))->toThrow(ValidationException::class);

    $this->drivers->voidTrip($trip, 'Entered twice', $this->admin);

    expect($trip->fresh()->void_reason)->toBe('Entered twice')
        ->and($driver->trips()->count())->toBe(1)
        ->and($this->drivers->paidTotals($driver))->toBe([])
        ->and(fn () => $this->drivers->updateTrip($trip->fresh(), ['arrived_on' => '2026-11-11'], $this->admin))->toThrow(AuthorizationException::class);
});

it('lets only people with agents.manage keep drivers', function () {
    $accountant = userWithRole(Role::Accountant, [branch('DHK')]);

    expect(fn () => $this->drivers->create(['name' => 'X', 'plate_kind' => 'arabic', 'plate_number' => '1', 'plate_region' => 'iraq'], $accountant))
        ->toThrow(AuthorizationException::class);
});

it('adds a driver with an Arabic plate and a trip from the admin screens', function (string $locale) {
    $this->admin->forceFill(['locale' => $locale])->save();
    $this->actingAs($this->admin->fresh());

    Livewire::test(ListDrivers::class)
        ->callAction('create', ['name' => 'Hawre', 'phone' => '0751 000 1122', 'car_type' => 'Kia Bongo', 'company_car' => false,
            'plate_kind' => 'arabic', 'plate_number_arabic' => '98765', 'plate_region' => 'sulaymaniyah'])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $driver = Driver::query()->where('name', 'Hawre')->sole();
    expect($driver->plate)->toBe('98765 السليمانية');

    Livewire::test(DriverTripsRelationManager::class, ['ownerRecord' => $driver, 'pageClass' => ViewDriver::class])
        ->callTableAction('add_trip', data: ['arrived_on' => '2026-11-18', 'amount' => '200000', 'currency' => 'IQD'])
        ->assertHasNoTableActionErrors();

    expect($this->drivers->paidTotals($driver))->toBe(['IQD 200,000']);

    $this->get('/admin/agents/drivers')->assertOk()->assertSee('98765');
    $this->get('/admin/agents/drivers/'.$driver->id)->assertOk()->assertSee('98765 السليمانية');
})->with(['en', 'ar', 'ckb']);
