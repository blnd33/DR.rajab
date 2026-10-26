<?php

use App\Enums\IssueStatus;
use App\Enums\IssueType;
use App\Enums\MovementType;
use App\Enums\Role;
use App\Enums\StockStatus;
use App\Enums\TransferLineResolution;
use App\Enums\TransferLineStatus;
use App\Enums\TransferStatus;
use App\Exceptions\Domain\StockRuleViolation;
use App\Models\Issue;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\StockUnit;
use App\Services\Branches\BranchService;
use App\Services\Movement\TransferService;
use App\Services\Stock\StockQuery;
use Database\Seeders\InventoryBaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/*
 * Transfers between branches (Module 9): draft → send → receive by scan → settle differences.
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    $this->transfers = app(TransferService::class);
    $this->dhk = branch('DHK');
    $this->bgw = branch('BGW');
    $this->admin = userWithRole(Role::SuperAdmin, [$this->dhk]);
    $this->sender = userWithRole(Role::BranchStaff, [$this->dhk]);
    $this->receiver = userWithRole(Role::BranchStaff, [$this->bgw]);
    $this->implant = Product::factory()->create(['sku' => 'KN-1']);
    $this->screw = Product::factory()->lot()->create(['sku' => 'SC-1']);

    stockIn($this->dhk, $this->admin, [
        ['product_id' => $this->implant->id, 'serial' => 'SN-1'],
        ['product_id' => $this->implant->id, 'serial' => 'SN-2'],
        ['product_id' => $this->screw->id, 'lot' => 'L1', 'quantity' => 30, 'unit_cost' => '2'],
    ]);

    $this->unit1 = StockUnit::query()->where('serial', 'SN-1')->firstOrFail();
    $this->unit2 = StockUnit::query()->where('serial', 'SN-2')->firstOrFail();
    $this->lot = StockBatch::query()->where('lot', 'L1')->firstOrFail();
});

function draftWithStock($test, int $lotQty = 10)
{
    $transfer = $test->transfers->createDraft($test->dhk, $test->bgw, $test->sender);
    $test->transfers->addUnit($transfer, $test->unit1, $test->sender);
    $test->transfers->addUnit($transfer, $test->unit2, $test->sender);
    $test->transfers->addBatch($transfer, $test->lot, $lotQty, $test->sender);

    return $transfer->refresh();
}

it('sends a transfer: units in transit, lots off the shelf, still on the sender books', function () {
    $transfer = $this->transfers->send(draftWithStock($this), $this->sender);

    expect($transfer->status)->toBe(TransferStatus::InTransit)
        ->and($transfer->number)->toStartWith('TRF')
        ->and($this->unit1->fresh()->status)->toBe(StockStatus::InTransit)
        ->and($this->unit1->fresh()->branch_id)->toBe($this->dhk->id)
        ->and($this->lot->fresh()->quantity)->toBe(20)
        ->and(StockMovement::query()->where('type', MovementType::TransferSend)->count())->toBe(3);

    $onHand = app(StockQuery::class)->onHand($this->admin)->where('branch_id', $this->dhk->id)->where('product_id', $this->screw->id)->sole();
    expect((int) $onHand->qty_in_transit)->toBe(10);

    // the receiving branch is told
    expect($this->receiver->notifications()->count())->toBe(1);
});

it('receives everything that was scanned', function () {
    $transfer = $this->transfers->send(draftWithStock($this), $this->sender);
    $lotItem = $transfer->items()->whereNotNull('from_batch_id')->firstOrFail();

    $transfer = $this->transfers->receive($transfer, [$this->unit1->id, $this->unit2->id], [$lotItem->id => 10], $this->receiver);

    expect($transfer->status)->toBe(TransferStatus::Received)
        ->and($this->unit1->fresh()->branch_id)->toBe($this->bgw->id)
        ->and($this->unit1->fresh()->status)->toBe(StockStatus::InStock)
        ->and((int) StockBatch::query()->where('branch_id', $this->bgw->id)->sum('quantity'))->toBe(10)
        ->and(Issue::query()->count())->toBe(0);
});

it('records missing, short and extra lines and opens one issue', function () {
    $transfer = $this->transfers->send(draftWithStock($this), $this->sender);
    $lotItem = $transfer->items()->whereNotNull('from_batch_id')->firstOrFail();

    $transfer = $this->transfers->receive($transfer, [$this->unit1->id], [$lotItem->id => 7], $this->receiver, ['UNKNOWN-99']);

    expect($transfer->status)->toBe(TransferStatus::ReceivedWithDiscrepancy)
        ->and($transfer->items()->where('stock_unit_id', $this->unit2->id)->first()->line_status)->toBe(TransferLineStatus::Missing)
        ->and($lotItem->fresh()->line_status)->toBe(TransferLineStatus::Short)
        ->and($transfer->items()->where('line_status', TransferLineStatus::Extra)->count())->toBe(1)
        ->and($this->unit2->fresh()->status)->toBe(StockStatus::InTransit);

    $issue = Issue::query()->sole();
    expect($issue->type)->toBe(IssueType::TransferDiscrepancy)
        ->and($issue->branch_id)->toBe($this->bgw->id)
        ->and($issue->status)->toBe(IssueStatus::Open);
});

it('settles differences: found, returned, written off — then closes the issue', function () {
    $transfer = $this->transfers->send(draftWithStock($this), $this->sender);
    $lotItem = $transfer->items()->whereNotNull('from_batch_id')->firstOrFail();
    $transfer = $this->transfers->receive($transfer, [], [$lotItem->id => 6], $this->receiver);

    $unit1Item = $transfer->items()->where('stock_unit_id', $this->unit1->id)->firstOrFail();
    $unit2Item = $transfer->items()->where('stock_unit_id', $this->unit2->id)->firstOrFail();

    // branch staff cannot settle — that is an admin decision
    expect(fn () => $this->transfers->resolveLine($unit1Item, TransferLineResolution::Found, $this->receiver))
        ->toThrow(AuthorizationException::class);

    $this->transfers->resolveLine($unit1Item, TransferLineResolution::Found, $this->admin);
    $this->transfers->resolveLine($unit2Item, TransferLineResolution::ReturnedToSender, $this->admin);
    expect(Issue::query()->sole()->status)->toBe(IssueStatus::Open);

    $this->transfers->resolveLine($lotItem->fresh(), TransferLineResolution::WrittenOff, $this->admin, 'Box fell off the car');

    expect($this->unit1->fresh()->branch_id)->toBe($this->bgw->id)
        ->and($this->unit2->fresh()->branch_id)->toBe($this->dhk->id)
        ->and($this->unit2->fresh()->status)->toBe(StockStatus::InStock)
        ->and(Issue::query()->sole()->status)->toBe(IssueStatus::Resolved)
        ->and(fn () => $this->transfers->resolveLine($unit1Item->fresh(), TransferLineResolution::Found, $this->admin))->toThrow(StockRuleViolation::class);

    // the 4 lost screws are in the ledger as a write-off with the note
    expect(StockMovement::query()->where('type', MovementType::WriteOff)->where('quantity', 4)->value('reason'))->toBe('Box fell off the car');
});

it('cancels a transfer in transit and puts everything back', function () {
    $transfer = $this->transfers->send(draftWithStock($this), $this->sender);

    $this->transfers->cancel($transfer, 'Wrong branch', $this->sender);

    expect($transfer->fresh()->status)->toBe(TransferStatus::Cancelled)
        ->and($this->unit1->fresh()->status)->toBe(StockStatus::InStock)
        ->and($this->lot->fresh()->quantity)->toBe(30)
        ->and(StockMovement::query()->where('type', MovementType::TransferCancel)->count())->toBe(3);

    expect(fn () => $this->transfers->cancel($transfer->fresh(), 'again', $this->sender))->toThrow(StockRuleViolation::class);
});

it('blocks expired items unless someone allowed gives a reason', function () {
    DB::table('stock_units')->where('id', $this->unit1->id)->update(['expiry_date' => now()->subDay()->toDateString()]);
    $transfer = $this->transfers->createDraft($this->dhk, $this->bgw, $this->sender);
    $this->transfers->addUnit($transfer, $this->unit1->fresh(), $this->sender);

    expect(fn () => $this->transfers->send($transfer, $this->sender, 'Needed'))->toThrow(StockRuleViolation::class)
        ->and(fn () => $this->transfers->send($transfer, $this->admin))->toThrow(StockRuleViolation::class);

    $this->transfers->send($transfer, $this->admin, 'Supplier takes it back at BGW');

    expect(StockMovement::query()->where('type', MovementType::TransferSend)->value('is_override'))->toBeTrue();
});

it('does not let a unit sit on two drafts, or be sent from another branch', function () {
    $first = $this->transfers->createDraft($this->dhk, $this->bgw, $this->sender);
    $this->transfers->addUnit($first, $this->unit1, $this->sender);
    $second = $this->transfers->createDraft($this->dhk, branch('EBL'), $this->sender);

    expect(fn () => $this->transfers->addUnit($second, $this->unit1, $this->sender))->toThrow(StockRuleViolation::class)
        ->and(fn () => $this->transfers->addUnit($first, $this->unit1, $this->sender))->toThrow(StockRuleViolation::class)
        ->and(fn () => $this->transfers->createDraft($this->bgw, $this->dhk, $this->sender))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->transfers->addBatch($first, $this->lot, 31, $this->sender))->toThrow(StockRuleViolation::class);
});

it('adds a unit by scanning its serial', function () {
    $transfer = $this->transfers->createDraft($this->dhk, $this->bgw, $this->sender);

    $this->transfers->addByScan($transfer, 'SN-1', $this->sender);

    expect($transfer->items()->where('stock_unit_id', $this->unit1->id)->exists())->toBeTrue();
});

it('blocks closing a branch with open transfers, and moves all stock in one go', function () {
    $transfer = $this->transfers->createDraft($this->dhk, $this->bgw, $this->sender);
    expect(app(BranchService::class)->closureBlockers($this->dhk))->not->toBe([]);
    $this->transfers->cancel($transfer, 'not needed', $this->sender);

    $all = $this->transfers->transferAll($this->dhk, $this->bgw, $this->admin);

    expect($all->status)->toBe(TransferStatus::InTransit)
        ->and($all->items()->count())->toBe(3)
        ->and($this->lot->fresh()->quantity)->toBe(0);
});
