<?php

use App\Enums\CheckoutItemOutcome;
use App\Enums\CustomerType;
use App\Enums\InstrumentStatus;
use App\Enums\IssueStatus;
use App\Enums\IssueType;
use App\Enums\KitCheckoutStatus;
use App\Enums\KitStatus;
use App\Enums\LocationType;
use App\Enums\MovementType;
use App\Enums\Role;
use App\Enums\StockStatus;
use App\Enums\SurgeryStatus;
use App\Exceptions\Domain\StockRuleViolation;
use App\Models\AssetMovement;
use App\Models\Customer;
use App\Models\InstrumentType;
use App\Models\Issue;
use App\Models\KitType;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\StockUnit;
use App\Models\SurgeryType;
use App\Services\Assets\AssetTransferService;
use App\Services\Assets\InstrumentService;
use App\Services\Assets\KitService;
use App\Services\Assets\OverdueKitNotifier;
use App\Services\Clinical\SurgeryService;
use App\Services\Movement\TransferService;
use Database\Seeders\InventoryBaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

/*
 * Kits & instruments (Module 18): prepare against the template → check out → surgery from
 * the kit → return → reconciliation (returned / used / unaccounted) → resolve; transfers.
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    $this->kits = app(KitService::class);
    $this->dhk = branch('DHK');
    $this->bgw = branch('BGW');
    $this->admin = userWithRole(Role::SuperAdmin, [$this->dhk]);
    $this->staff = userWithRole(Role::BranchStaff, [$this->dhk]);
    $this->doctor = userWithRole(Role::Doctor, [$this->dhk]);
    $this->hospital = Customer::query()->create(['code' => 'HSP-1', 'type' => CustomerType::Hospital, 'name' => ['en' => 'Azadi']]);

    $this->implant = Product::factory()->create(['sku' => 'KN-1']);
    $this->screw = Product::factory()->lot()->create(['sku' => 'SC-1']);
    stockIn($this->dhk, $this->admin, [
        ['product_id' => $this->implant->id, 'serial' => 'SN-1'],
        ['product_id' => $this->implant->id, 'serial' => 'SN-2'],
        ['product_id' => $this->implant->id, 'serial' => 'SN-3'],
        ['product_id' => $this->screw->id, 'lot' => 'L1', 'quantity' => 30, 'unit_cost' => '2'],
    ]);

    $this->guide = InstrumentType::query()->create(['code' => 'GUIDE', 'name' => ['en' => 'Cutting guide']]);
    $this->type = KitType::query()->create(['code' => 'KNEE', 'name' => ['en' => 'Knee kit']]);
    $this->type->templateItems()->createMany([
        ['instrument_type_id' => $this->guide->id, 'expected_qty' => 1],
        ['product_id' => $this->implant->id, 'expected_qty' => 2],
        ['product_id' => $this->screw->id, 'expected_qty' => 10, 'is_required' => false],
    ]);

    $this->instrument = app(InstrumentService::class)->create($this->dhk, ['instrument_type_id' => $this->guide->id], $this->admin);
    $this->kit = $this->kits->create($this->dhk, ['kit_type_id' => $this->type->id], $this->admin);

    $this->unit = fn (string $serial) => StockUnit::query()->where('serial', $serial)->firstOrFail();
});

function prepareKit($test): void
{
    $test->kits->addByScan($test->kit, $test->instrument->barcode, $test->staff);
    $test->kits->addByScan($test->kit, 'SN-1', $test->staff);
    $test->kits->addByScan($test->kit, 'SN-2', $test->staff);
    $test->kits->addBatch($test->kit, StockBatch::query()->where('lot', 'L1')->firstOrFail(), 10, $test->staff);
}

it('creates kits and instruments with codes and a ledger row', function () {
    expect($this->kit->code)->toBe('KIT-DHK-0001')
        ->and($this->kit->barcode)->toBe('KIT-DHK-0001')
        ->and($this->instrument->code)->toBe('INS-DHK-0001')
        ->and(AssetMovement::query()->count())->toBe(2);
});

it('prepares a kit and compares it with the template', function () {
    $this->kits->addByScan($this->kit, $this->instrument->barcode, $this->staff);
    $this->kits->addByScan($this->kit, 'SN-1', $this->staff);

    $compare = $this->kits->compare($this->kit);
    expect($compare['complete'])->toBeFalse()
        ->and(collect($compare['lines'])->firstWhere('kind', 'product')['missing'])->toBe(1);

    $this->kits->addByScan($this->kit, 'SN-2', $this->staff);
    $this->kits->addByScan($this->kit, 'SC-1', $this->staff); // one screw per scan

    $compare = $this->kits->compare($this->kit);
    expect($compare['complete'])->toBeTrue()
        ->and($this->unit->__invoke('SN-1')->status)->toBe(StockStatus::InKit)
        ->and($this->instrument->fresh()->status)->toBe(InstrumentStatus::InKit)
        ->and((int) StockBatch::query()->where('kit_id', $this->kit->id)->sum('quantity'))->toBe(1)
        ->and(StockMovement::query()->where('type', MovementType::KitLoad)->count())->toBe(4);

    // out of the kit again
    $this->kits->removeUnit($this->unit->__invoke('SN-2'), $this->staff);
    expect($this->unit->__invoke('SN-2')->status)->toBe(StockStatus::InStock)->and($this->unit->__invoke('SN-2')->kit_id)->toBeNull();
});

it('runs the full flow: check out, surgery from the kit, return, unaccounted, resolve', function () {
    prepareKit($this);
    $checkout = $this->kits->checkout($this->kit, $this->hospital, $this->staff, $this->doctor, plannedDate: now()->toDateString());

    expect($checkout->number)->toStartWith('KCO')
        ->and($this->kit->fresh()->status)->toBe(KitStatus::OutAtHospital)
        ->and($this->unit->__invoke('SN-1')->status)->toBe(StockStatus::OutAtHospital)
        ->and($this->instrument->fresh()->status)->toBe(InstrumentStatus::OutAtHospital)
        ->and($checkout->items()->count())->toBe(4)
        ->and(StockBatch::query()->where('kit_id', $this->kit->id)->where('location_type', LocationType::Hospital)->value('quantity'))->toBe(10);

    // The doctor uses SN-1 and 4 screws from the kit.
    $surgery = app(SurgeryService::class)->sync([
        'client_uuid' => (string) Str::uuid(),
        'branch_id' => $this->dhk->id,
        'surgery_type_id' => SurgeryType::query()->create(['code' => 'TKA', 'name' => ['en' => 'Total knee']])->id,
        'customer_id' => $this->hospital->id,
        'new_patient' => ['full_name' => 'Patient One'],
        'kit_checkout_id' => $checkout->id,
        'items' => [['raw' => 'SN-1'], ['raw' => 'SC-1', 'quantity' => 4]],
        'complete' => true,
    ], $this->doctor);

    expect($surgery->status)->toBe(SurgeryStatus::Completed)
        ->and($surgery->items()->whereNotNull('warnings')->count())->toBe(0)
        ->and($surgery->kit_id)->toBe($this->kit->id);

    // Back: SN-2 is not scanned, 5 screws come back (1 unaccounted), the guide is fine.
    $lotItem = $checkout->items()->whereNotNull('stock_batch_id')->firstOrFail();
    $checkout = $this->kits->return($checkout, [], [], [$lotItem->id => 5], $this->staff);

    expect($checkout->status)->toBe(KitCheckoutStatus::ReconciledWithIssues)
        ->and($this->kit->fresh()->status)->toBe(KitStatus::Available)
        ->and($checkout->items()->where('stock_unit_id', $this->unit->__invoke('SN-1')->id)->first()->outcome)->toBe(CheckoutItemOutcome::UsedInSurgery)
        ->and($checkout->items()->where('stock_unit_id', $this->unit->__invoke('SN-2')->id)->first()->outcome)->toBe(CheckoutItemOutcome::Unaccounted)
        ->and($lotItem->fresh()->qty_used)->toBe(4)
        ->and($lotItem->fresh()->qty_returned)->toBe(5)
        ->and($lotItem->fresh()->qty_unaccounted)->toBe(1)
        ->and($this->instrument->fresh()->status)->toBe(InstrumentStatus::InKit);

    $issue = Issue::query()->where('type', IssueType::UnaccountedImplant)->sole();
    expect($issue->status)->toBe(IssueStatus::Open)->and($this->admin->notifications()->count())->toBeGreaterThanOrEqual(1);

    // Branch staff cannot settle; the Super Admin can.
    $missingUnit = $checkout->items()->where('stock_unit_id', $this->unit->__invoke('SN-2')->id)->firstOrFail();
    expect(fn () => $this->kits->resolveUnaccounted($missingUnit, 'found', $this->staff))->toThrow(AuthorizationException::class);

    $this->kits->resolveUnaccounted($missingUnit, 'found', $this->admin, 'Was in the theatre drawer');
    expect($this->unit->__invoke('SN-2')->status)->toBe(StockStatus::InKit);

    $this->kits->resolveUnaccounted($lotItem->fresh(), 'lost', $this->admin);
    expect($issue->fresh()->status)->toBe(IssueStatus::Resolved)
        ->and(StockMovement::query()->where('type', MovementType::WriteOff)->where('quantity', 1)->exists())->toBeTrue()
        ->and(fn () => $this->kits->resolveUnaccounted($lotItem->fresh(), 'lost', $this->admin))->toThrow(StockRuleViolation::class);
});

it('flags missing and damaged instruments on return', function () {
    prepareKit($this);
    $checkout = $this->kits->checkout($this->kit, $this->hospital, $this->staff);
    $checkout = $this->kits->return($checkout, [$this->instrument->id => 'damaged'], [$this->unit->__invoke('SN-1')->id, $this->unit->__invoke('SN-2')->id],
        [$checkout->items()->whereNotNull('stock_batch_id')->value('id') => 10], $this->staff);

    expect($checkout->status)->toBe(KitCheckoutStatus::ReconciledWithIssues)
        ->and($this->instrument->fresh()->status)->toBe(InstrumentStatus::Damaged)
        ->and($this->instrument->fresh()->kit_id)->toBeNull()
        ->and(Issue::query()->count())->toBe(0)
        ->and($this->unit->__invoke('SN-1')->status)->toBe(StockStatus::InKit);
});

it('refuses to prepare or check out a kit that is out', function () {
    prepareKit($this);
    $this->kits->checkout($this->kit, $this->hospital, $this->staff);

    expect(fn () => $this->kits->addByScan($this->kit->fresh(), 'SN-3', $this->staff))->toThrow(StockRuleViolation::class)
        ->and(fn () => $this->kits->checkout($this->kit->fresh(), $this->hospital, $this->staff))->toThrow(StockRuleViolation::class);
});

it('sends a kit with everything in it on a transfer', function () {
    prepareKit($this);
    $receiver = userWithRole(Role::BranchStaff, [$this->bgw]);
    $transfers = app(TransferService::class);
    $transfer = $transfers->createDraft($this->dhk, $this->bgw, $this->staff);
    app(AssetTransferService::class)->addKit($transfer, $this->kit, $this->staff);

    $transfer = $transfers->send($transfer, $this->staff, photo: transferPhoto());
    expect($this->kit->fresh()->status)->toBe(KitStatus::InTransit)
        ->and($this->instrument->fresh()->status)->toBe(InstrumentStatus::InTransit);

    $transfers->receive($transfer, [], [], $receiver, photo: transferPhoto());

    expect($this->kit->fresh()->branch_id)->toBe($this->bgw->id)
        ->and($this->kit->fresh()->status)->toBe(KitStatus::Available)
        ->and($this->instrument->fresh()->branch_id)->toBe($this->bgw->id)
        ->and($this->unit->__invoke('SN-1')->branch_id)->toBe($this->bgw->id)
        ->and($this->unit->__invoke('SN-1')->status)->toBe(StockStatus::InKit)
        ->and((int) StockBatch::query()->where('kit_id', $this->kit->id)->where('branch_id', $this->bgw->id)->sum('quantity'))->toBe(10);
});

it('reminds about overdue kits', function () {
    prepareKit($this);
    $checkout = $this->kits->checkout($this->kit, $this->hospital, $this->staff);
    $checkout->forceFill(['due_back_at' => now()->subDay()])->save();

    expect($this->kit->fresh()->isOverdue())->toBeTrue()
        ->and(app(OverdueKitNotifier::class)->notify())->toBe(1)
        ->and($this->staff->notifications()->count())->toBe(1);
});

it('keeps instruments in a kit until they are taken out', function () {
    $service = app(InstrumentService::class);
    $this->kits->addInstrument($this->kit, $this->instrument, $this->staff);

    expect(fn () => $service->sendToMaintenance($this->instrument->fresh(), $this->staff))->toThrow(StockRuleViolation::class);

    $this->kits->removeInstrument($this->instrument->fresh(), $this->staff);
    $service->sendToMaintenance($this->instrument->fresh(), $this->staff, 'Blade blunt');
    $service->backFromMaintenance($this->instrument->fresh(), $this->staff);

    expect($this->instrument->fresh()->status)->toBe(InstrumentStatus::Available)
        ->and(AssetMovement::query()->where('asset_id', $this->instrument->id)->where('asset_type', $this->instrument->getMorphClass())->count())->toBe(5);
});
