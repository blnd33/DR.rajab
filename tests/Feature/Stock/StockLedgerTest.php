<?php

use App\Enums\MovementType;
use App\Enums\Role;
use App\Enums\StockStatus;
use App\Exceptions\Domain\StockRuleViolation;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\StockUnit;
use App\Services\Stock\Data\ReceiveLine;
use App\Services\Stock\StockMovementService;
use Database\Seeders\InventoryBaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/*
 * The stock ledger: every change is a movement, quantities never go negative,
 * serials are unique, expired items are blocked, rows can't be edited.
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    $this->stock = app(StockMovementService::class);
    $this->dhk = branch('DHK');
    $this->staff = userWithRole(Role::BranchStaff, [$this->dhk]);
    $this->implant = Product::factory()->create(['sku' => 'KN-1']);
    $this->screw = Product::factory()->lot()->create(['sku' => 'SC-1', 'purchase_price' => '2.5000']);
});

function line(Product $product, array $overrides = []): ReceiveLine
{
    return ReceiveLine::fromArray($overrides + [
        'product_id' => $product->id,
        'quantity' => 1,
        'expiry_date' => now()->addYear()->toDateString(),
        'unit_cost' => '100',
        'cost_currency' => 'USD',
    ]);
}

it('receives serial units and lots with one ledger row each', function () {
    $result = $this->stock->receive($this->dhk, [
        line($this->implant, ['serial' => 'SN-1']),
        line($this->implant, ['serial' => 'SN-2']),
        line($this->screw, ['lot' => 'L1', 'quantity' => 40, 'unit_cost' => '2.5']),
    ], $this->staff);

    expect($result->quantity)->toBe(42)
        ->and(StockUnit::query()->where('status', StockStatus::InStock)->count())->toBe(2)
        ->and(StockBatch::query()->sum('quantity'))->toEqual(40)
        ->and(StockMovement::query()->where('movement_group', $result->movementGroup)->count())->toBe(3)
        ->and(StockMovement::query()->first()->type)->toBe(MovementType::OpeningBalance)
        ->and(StockMovement::query()->first()->user_id)->toBe($this->staff->id);
});

it('adds the same lot at the same cost to one bucket, and keeps another cost apart', function () {
    $this->stock->receive($this->dhk, [line($this->screw, ['lot' => 'L1', 'quantity' => 10, 'unit_cost' => '2.5'])], $this->staff);
    $this->stock->receive($this->dhk, [line($this->screw, ['lot' => 'L1', 'quantity' => 5, 'unit_cost' => '2.5'])], $this->staff);
    $this->stock->receive($this->dhk, [line($this->screw, ['lot' => 'L1', 'quantity' => 3, 'unit_cost' => '3'])], $this->staff);

    expect(StockBatch::query()->count())->toBe(2)
        ->and(StockBatch::query()->where('unit_cost', '2.5')->value('quantity'))->toBe(15);
});

it('never accepts the same serial twice', function () {
    $this->stock->receive($this->dhk, [line($this->implant, ['serial' => 'SN-1'])], $this->staff);

    expect(fn () => $this->stock->receive($this->dhk, [line($this->implant, ['serial' => 'SN-1'])], $this->staff))
        ->toThrow(StockRuleViolation::class);

    expect(fn () => $this->stock->receive($this->dhk, [line($this->implant, ['serial' => 'SN-7']), line($this->implant, ['serial' => 'SN-7'])], $this->staff))
        ->toThrow(StockRuleViolation::class)
        ->and(StockUnit::query()->count())->toBe(1);
});

it('rolls back the whole receipt when one line is wrong', function () {
    expect(fn () => $this->stock->receive($this->dhk, [
        line($this->implant, ['serial' => 'SN-1']),
        line($this->implant, ['serial' => null]),
    ], $this->staff))->toThrow(StockRuleViolation::class);

    expect(StockUnit::query()->count())->toBe(0)->and(StockMovement::query()->count())->toBe(0);
});

it('checks the line rules: quantity 1 for serials, expiry when required, valid currency', function (array $overrides, string $product) {
    expect(fn () => $this->stock->receive($this->dhk, [line($this->{$product}, $overrides)], $this->staff))
        ->toThrow(StockRuleViolation::class);
})->with([
    'serial qty 2' => [['serial' => 'S', 'quantity' => 2], 'implant'],
    'lot with serial' => [['serial' => 'S', 'quantity' => 2], 'screw'],
    'missing expiry' => [['serial' => 'S', 'expiry_date' => null], 'implant'],
    'bad date' => [['serial' => 'S', 'expiry_date' => '2027-02-30'], 'implant'],
    'bad currency' => [['serial' => 'S', 'cost_currency' => 'GBP'], 'implant'],
    'negative cost' => [['serial' => 'S', 'unit_cost' => '-1'], 'implant'],
]);

it('only lets users receive in their own branches', function () {
    expect(fn () => $this->stock->receive(branch('EBL'), [line($this->implant, ['serial' => 'SN-1'])], $this->staff))
        ->toThrow(AuthorizationException::class);

    $doctor = userWithRole(Role::Doctor, [$this->dhk]);

    expect(fn () => $this->stock->receive($this->dhk, [line($this->implant, ['serial' => 'SN-2'])], $doctor))
        ->toThrow(AuthorizationException::class);
});

it('records expired items with a warning and blocks their use without an override', function () {
    $result = $this->stock->receive($this->dhk, [line($this->implant, ['serial' => 'OLD-1', 'expiry_date' => now()->subDay()->toDateString()])], $this->staff);
    $unit = $result->units[0];

    expect($result->warnings)->toHaveCount(1)
        ->and($unit->isBlockedByExpiry())->toBeTrue();

    expect(fn () => $this->stock->assertUsable($unit, $this->staff))->toThrow(StockRuleViolation::class);
    expect(fn () => $this->stock->assertUsable($unit, $this->staff, 'Patient consent'))->toThrow(StockRuleViolation::class);

    $admin = userWithRole(Role::SuperAdmin, [$this->dhk]);

    expect($this->stock->assertUsable($unit, $admin, 'No alternative, surgeon decision'))->toBeTrue();
});

it('corrects a unit with a reason and keeps old → new on the ledger', function () {
    $unit = $this->stock->receive($this->dhk, [line($this->implant, ['serial' => 'SN-1', 'lot' => 'L1'])], $this->staff)->units[0];
    $accountant = userWithRole(Role::Accountant, [], ['all_branches' => true]);

    $movement = $this->stock->correctUnit($unit, ['lot' => 'L2', 'base_unit_cost' => '120'], 'Typed wrongly', $accountant);

    expect($movement->type)->toBe(MovementType::CostAdjustment)
        ->and($movement->details)->toBe(['lot' => ['old' => 'L1', 'new' => 'L2'], 'base_unit_cost' => ['old' => '100.0000', 'new' => '120.0000']])
        ->and($unit->fresh()->unit_cost)->toBe('120.0000')
        ->and($this->stock->correctUnit($unit->fresh(), ['lot' => 'L2'], 'Again', $accountant))->toBeNull();

    expect(fn () => $this->stock->correctUnit($unit, ['lot' => 'L3'], '', $accountant))->toThrow(StockRuleViolation::class);
});

it('moves a lot bucket when its lot or expiry is corrected', function () {
    $this->stock->receive($this->dhk, [line($this->screw, ['lot' => 'L1', 'quantity' => 10, 'unit_cost' => '2.5'])], $this->staff);
    $this->stock->receive($this->dhk, [line($this->screw, ['lot' => 'L2', 'quantity' => 4, 'unit_cost' => '2.5'])], $this->staff);
    $admin = userWithRole(Role::SuperAdmin, [$this->dhk]);

    $source = StockBatch::query()->where('lot', 'L1')->firstOrFail();
    $target = StockBatch::query()->where('lot', 'L2')->firstOrFail();

    $movement = $this->stock->correctBatch($source, ['lot' => 'L2', 'expiry_date' => $target->expiry_date->toDateString()], 'Same lot, label misread', $admin);

    expect($source->fresh()->quantity)->toBe(0)
        ->and($target->fresh()->quantity)->toBe(14)
        ->and($movement->from_batch_id)->toBe($source->id)
        ->and($movement->to_batch_id)->toBe($target->id)
        ->and($movement->quantity)->toBe(10);
});

it('overrides a status only with stock.adjust and a reason', function () {
    $unit = $this->stock->receive($this->dhk, [line($this->implant, ['serial' => 'SN-1'])], $this->staff)->units[0];

    expect(fn () => $this->stock->overrideStatus($unit, StockStatus::Lost, 'Gone', $this->staff))->toThrow(AuthorizationException::class);

    $admin = userWithRole(Role::SuperAdmin, [$this->dhk]);
    $movement = $this->stock->overrideStatus($unit, StockStatus::Lost, 'Recorded in the wrong place', $admin);

    expect($unit->fresh()->status)->toBe(StockStatus::Lost)
        ->and($movement->is_override)->toBeTrue()
        ->and($movement->from_status)->toBe(StockStatus::InStock);
});

it('keeps the stock ledger append-only, even for raw queries', function () {
    $this->stock->receive($this->dhk, [line($this->implant, ['serial' => 'SN-1'])], $this->staff);
    $movement = StockMovement::query()->firstOrFail();

    expect(fn () => $movement->update(['reason' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => $movement->delete())->toThrow(LogicException::class)
        ->and(fn () => DB::table('stock_movements')->update(['reason' => 'x']))->toThrow(Exception::class)
        ->and(fn () => DB::table('stock_movements')->delete())->toThrow(Exception::class);
});

it('refuses stock in a closed branch', function () {
    $this->dhk->forceFill(['status' => 'closed', 'close_reason' => 'test'])->save();

    expect(fn () => $this->stock->receive($this->dhk->fresh(), [line($this->implant, ['serial' => 'SN-1'])], userWithRole(Role::SuperAdmin)))
        ->toThrow(StockRuleViolation::class);
});
