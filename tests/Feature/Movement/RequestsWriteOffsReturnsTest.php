<?php

use App\Enums\MovementType;
use App\Enums\RequestPriority;
use App\Enums\Role;
use App\Enums\StockRequestStatus;
use App\Enums\StockStatus;
use App\Enums\SupplierReturnStatus;
use App\Enums\TransferStatus;
use App\Enums\WriteOffReason;
use App\Enums\WriteOffStatus;
use App\Exceptions\Domain\StockRuleViolation;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\StockUnit;
use App\Models\Supplier;
use App\Models\WriteOff;
use App\Services\Movement\ReturnService;
use App\Services\Movement\StockRequestService;
use App\Services\Movement\TransferService;
use App\Services\Movement\WriteOffService;
use App\Services\Stock\StockMovementService;
use Database\Seeders\InventoryBaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

/*
 * Stock requests (Module 10), write-offs (Module 13) and returns (Modules 11–12).
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    $this->dhk = branch('DHK');
    $this->bgw = branch('BGW');
    $this->admin = userWithRole(Role::SuperAdmin, [$this->dhk]);
    $this->dhkStaff = userWithRole(Role::BranchStaff, [$this->dhk]);
    $this->bgwStaff = userWithRole(Role::BranchStaff, [$this->bgw]);
    $this->implant = Product::factory()->create(['sku' => 'KN-1']);
    $this->screw = Product::factory()->lot()->create(['sku' => 'SC-1']);

    stockIn($this->dhk, $this->admin, [
        ['product_id' => $this->implant->id, 'serial' => 'SN-1'],
        ['product_id' => $this->implant->id, 'serial' => 'SN-2'],
        ['product_id' => $this->screw->id, 'lot' => 'L1', 'quantity' => 30, 'unit_cost' => '2'],
    ]);
});

it('runs a stock request: create → approve → draft transfer → received → fulfilled', function () {
    $requests = app(StockRequestService::class);
    $request = $requests->create($this->bgw, [
        ['product_id' => $this->implant->id, 'quantity' => 1],
        ['product_id' => $this->screw->id, 'quantity' => 12],
    ], $this->bgwStaff, priority: RequestPriority::Urgent);

    expect($request->number)->toStartWith('REQ')
        ->and($request->status)->toBe(StockRequestStatus::Pending)
        ->and($request->items()->whereNotNull('source_branch_id')->count())->toBe(2) // DHK suggested: it has the stock
        ->and($this->admin->notifications()->count())->toBe(1);

    expect(fn () => $requests->approve($request, [], $this->bgwStaff))->toThrow(AuthorizationException::class);

    $screwLine = $request->items()->where('product_id', $this->screw->id)->firstOrFail();
    $request = $requests->approve($request, [$screwLine->id => ['quantity_approved' => 10, 'source_branch_id' => $this->dhk->id]], $this->admin);

    expect($request->status)->toBe(StockRequestStatus::PartiallyApproved)
        ->and($request->transfers()->count())->toBe(1);

    $transfer = $request->transfers()->sole();
    expect($transfer->status)->toBe(TransferStatus::Draft)
        ->and($transfer->from_branch_id)->toBe($this->dhk->id)
        ->and($this->dhkStaff->notifications()->count())->toBe(1)
        ->and($this->bgwStaff->notifications()->count())->toBe(1);

    $transfers = app(TransferService::class);
    $transfers->addByScan($transfer, 'SN-1', $this->dhkStaff);
    $transfers->addBatch($transfer, StockBatch::query()->where('lot', 'L1')->firstOrFail(), 10, $this->dhkStaff);
    $transfer = $transfers->send($transfer, $this->dhkStaff);
    $lotItem = $transfer->items()->whereNotNull('from_batch_id')->firstOrFail();
    $transfers->receive($transfer, [StockUnit::query()->where('serial', 'SN-1')->value('id')], [$lotItem->id => 10], $this->bgwStaff);

    expect($request->fresh()->status)->toBe(StockRequestStatus::Fulfilled);
});

it('rejects a request with a reason, and refuses empty or self requests', function () {
    $requests = app(StockRequestService::class);

    expect(fn () => $requests->create($this->bgw, [], $this->bgwStaff))->toThrow(StockRuleViolation::class)
        ->and(fn () => $requests->create($this->bgw, [['product_id' => $this->screw->id, 'quantity' => 1]], $this->bgwStaff, $this->bgw))->toThrow(StockRuleViolation::class);

    $request = $requests->create($this->bgw, [['product_id' => $this->screw->id, 'quantity' => 5]], $this->bgwStaff);
    $requests->reject($request, 'Order from the supplier instead', $this->admin);

    expect($request->fresh()->status)->toBe(StockRequestStatus::Rejected)
        ->and($request->fresh()->review_note)->toBe('Order from the supplier instead')
        ->and(fn () => $requests->approve($request->fresh(), [], $this->admin))->toThrow(StockRuleViolation::class);
});

it('moves stock on a write-off only when it is approved', function () {
    $writeOffs = app(WriteOffService::class);
    $unit = StockUnit::query()->where('serial', 'SN-1')->firstOrFail();
    $batch = StockBatch::query()->where('lot', 'L1')->firstOrFail();

    $writeOff = $writeOffs->report($this->dhk, WriteOffReason::Damaged, [
        ['stock_unit_id' => $unit->id],
        ['stock_batch_id' => $batch->id, 'quantity' => 4],
    ], $this->dhkStaff, 'Dropped on the floor');

    expect($writeOff->number)->toStartWith('WOF')
        ->and($writeOff->status)->toBe(WriteOffStatus::Pending)
        ->and($unit->fresh()->status)->toBe(StockStatus::InStock)
        ->and($batch->fresh()->quantity)->toBe(30)
        ->and((string) $writeOff->costByCurrency()['USD'])->toBe('108.0000');

    expect(fn () => $writeOffs->approve($writeOff, $this->dhkStaff))->toThrow(AuthorizationException::class);

    $writeOffs->approve($writeOff, $this->admin);

    expect($writeOff->fresh()->status)->toBe(WriteOffStatus::Approved)
        ->and($unit->fresh()->status)->toBe(StockStatus::Damaged)
        ->and($batch->fresh()->quantity)->toBe(26)
        ->and(StockMovement::query()->where('type', MovementType::WriteOff)->count())->toBe(2);
});

it('keeps stock when a write-off is rejected', function () {
    $writeOffs = app(WriteOffService::class);
    $unit = StockUnit::query()->where('serial', 'SN-1')->firstOrFail();
    $writeOff = $writeOffs->report($this->dhk, WriteOffReason::Lost, [['stock_unit_id' => $unit->id]], $this->dhkStaff);

    $writeOffs->reject($writeOff, 'It was found in the kit', $this->admin);

    expect($writeOff->fresh()->status)->toBe(WriteOffStatus::Rejected)
        ->and($unit->fresh()->status)->toBe(StockStatus::InStock)
        ->and(StockMovement::query()->where('type', MovementType::WriteOff)->count())->toBe(0);
});

it('takes customer returns back: good to the shelf, damaged to a pending write-off', function () {
    $stock = app(StockMovementService::class);
    $returned = StockUnit::query()->where('serial', 'SN-1')->firstOrFail();
    $damaged = StockUnit::query()->where('serial', 'SN-2')->firstOrFail();

    foreach ([$returned, $damaged] as $unit) {
        $stock->moveUnitOut($unit, StockStatus::OutAtHospital, MovementType::KitCheckout, $this->admin, $unit, (string) Str::uuid(), 'stock.adjust');
    }

    $return = app(ReturnService::class)->receiveFromCustomer($this->bgw, [
        ['stock_unit_id' => $returned->id, 'reason' => 'unused', 'condition' => 'good'],
        ['stock_unit_id' => $damaged->id, 'reason' => 'wrong_size', 'condition' => 'damaged'],
        ['product_id' => $this->screw->id, 'lot' => 'L9', 'quantity' => 3, 'expiry_date' => now()->addYear()->toDateString(), 'reason' => 'unused', 'condition' => 'good'],
    ], $this->bgwStaff);

    expect($return->number)->toStartWith('RET')
        ->and($returned->fresh()->status)->toBe(StockStatus::InStock)
        ->and($returned->fresh()->branch_id)->toBe($this->bgw->id)
        ->and(StockBatch::query()->where('lot', 'L9')->value('quantity'))->toBe(3)
        ->and(WriteOff::query()->where('status', WriteOffStatus::Pending)->count())->toBe(1);

    // a unit that is already on our shelf cannot come back
    expect(fn () => app(ReturnService::class)->receiveFromCustomer($this->bgw, [['stock_unit_id' => $returned->id, 'reason' => 'unused', 'condition' => 'good']], $this->bgwStaff))
        ->toThrow(StockRuleViolation::class);
});

it('returns items to a supplier: draft holds nothing, send takes them out of stock', function () {
    $returns = app(ReturnService::class);
    $supplier = Supplier::query()->create(['code' => 'SUP1', 'name' => 'Implant Co']);
    $unit = StockUnit::query()->where('serial', 'SN-1')->firstOrFail();
    $batch = StockBatch::query()->where('lot', 'L1')->firstOrFail();

    $return = $returns->createSupplierReturn($supplier, $this->dhk, [
        ['stock_unit_id' => $unit->id, 'reason' => 'defective'],
        ['stock_batch_id' => $batch->id, 'quantity' => 5, 'reason' => 'recall'],
    ], $this->dhkStaff);

    expect($return->number)->toStartWith('SRT')
        ->and($return->status)->toBe(SupplierReturnStatus::Draft)
        ->and($unit->fresh()->status)->toBe(StockStatus::InStock);

    $returns->sendSupplierReturn($return, $this->dhkStaff);

    expect($return->fresh()->status)->toBe(SupplierReturnStatus::Sent)
        ->and($unit->fresh()->status)->toBe(StockStatus::ReturnedToSupplier)
        ->and($batch->fresh()->quantity)->toBe(25)
        ->and(fn () => $returns->cancelSupplierReturn($return->fresh(), 'oops', $this->dhkStaff))->toThrow(StockRuleViolation::class);
});
