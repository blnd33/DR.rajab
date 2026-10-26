<?php

use App\Enums\ApprovalStatus;
use App\Enums\MovementType;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use App\Enums\ReceivingStatus;
use App\Enums\Role;
use App\Enums\StockStatus;
use App\Enums\SupplierPaymentStatus;
use App\Enums\SupplierReturnStatus;
use App\Exceptions\Domain\MoneyRuleViolation;
use App\Models\Approval;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\StockUnit;
use App\Models\Supplier;
use App\Services\Money\ApprovalService;
use App\Services\Money\CashBoxService;
use App\Services\Money\FxService;
use App\Services\Money\PartyLedgerService;
use App\Services\Movement\ReturnService;
use App\Services\Purchasing\CreditNoteService;
use App\Services\Purchasing\LandedCostService;
use App\Services\Purchasing\PurchaseReceiveService;
use App\Services\Purchasing\PurchaseService;
use App\Services\Purchasing\SupplierPaymentService;
use App\Services\Settings\SettingsRepository;
use App\Services\Stock\StockMovementService;
use Database\Seeders\InventoryBaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

/*
 * Suppliers & purchases (Module 7): invoice → receive with the true cost → landed costs →
 * payments in another currency (gain / loss) with approvals → statements and credit notes.
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    $this->dhk = branch('DHK');
    $this->admin = userWithRole(Role::SuperAdmin, [$this->dhk]);
    $this->accountant = userWithRole(Role::Accountant, [$this->dhk]);
    $this->staff = userWithRole(Role::BranchStaff, [$this->dhk]);
    app(FxService::class)->setRate('USD', 'IQD', '1460', $this->admin);
    app(FxService::class)->setRate('EUR', 'USD', '1.10', $this->admin);

    $this->supplier = Supplier::query()->create(['code' => 'NVJ', 'name' => 'NovaJoint', 'default_currency' => 'EUR', 'payment_terms_days' => 60]);
    $this->implant = Product::factory()->create(['sku' => 'KN-1']);
    $this->screw = Product::factory()->lot()->create(['sku' => 'SC-1']);
    $this->purchases = app(PurchaseService::class);
    $this->party = app(PartyLedgerService::class);
    $this->usdBox = app(CashBoxService::class)->create($this->dhk, ['name' => ['en' => 'Bank'], 'type' => 'bank', 'currency' => 'USD', 'opening_balance' => '50000'], $this->admin);
});

function eurInvoice($test, string $number = 'NJ-100')
{
    return $test->purchases->create($test->supplier, $test->dhk, [
        'supplier_invoice_number' => $number,
        'invoice_date' => '2026-10-01',
        'currency' => 'EUR',
        'discount' => '100',
        'lines' => [
            ['product_id' => $test->implant->id, 'quantity' => 2, 'unit_price' => '500'],
            ['product_id' => $test->screw->id, 'quantity' => 100, 'unit_price' => '10'],
        ],
    ], $test->admin);
}

it('creates a supplier invoice, spreads the discount and books the payable on confirm', function () {
    $purchase = eurInvoice($this);

    expect($purchase->number)->toStartWith('PUR')
        ->and((string) $purchase->total)->toBe('1900.0000')
        ->and($purchase->due_date->toDateString())->toBe('2026-11-30')
        ->and((string) $purchase->items()->where('product_id', $this->implant->id)->value('true_unit_cost'))->toBe('475.0000')
        ->and($this->party->balances($this->supplier))->toBe([]);

    $this->purchases->confirm($purchase, $this->admin);

    expect($this->party->balances($this->supplier))->toBe(['EUR' => '1900.0000'])
        ->and(fn () => eurInvoice($this))->toThrow(MoneyRuleViolation::class); // same supplier invoice number
});

it('receives purchased items with their true cost and purchase line, in parts', function () {
    $purchase = $this->purchases->confirm(eurInvoice($this), $this->admin);
    $implantLine = $purchase->items()->where('product_id', $this->implant->id)->first();
    $screwLine = $purchase->items()->where('product_id', $this->screw->id)->first();
    $receiver = app(PurchaseReceiveService::class);

    $receiver->receive($purchase, [
        ['purchase_item_id' => $implantLine->id, 'serial' => 'SN-P1', 'expiry_date' => '2030-01-01'],
        ['purchase_item_id' => $screwLine->id, 'lot' => 'L9', 'quantity' => 60, 'expiry_date' => '2030-01-01'],
    ], $this->staff);

    $unit = StockUnit::query()->where('serial', 'SN-P1')->sole();
    expect((string) $unit->unit_cost)->toBe('475.0000')
        ->and($unit->cost_currency)->toBe('EUR')
        ->and($unit->purchase_item_id)->toBe($implantLine->id)
        ->and(StockMovement::query()->where('type', MovementType::PurchaseReceipt)->count())->toBe(2)
        ->and($purchase->fresh()->receiving_status)->toBe(ReceivingStatus::PartiallyReceived)
        ->and(fn () => $receiver->receive($purchase, [['purchase_item_id' => $screwLine->id, 'lot' => 'L9', 'quantity' => 41]], $this->staff))->toThrow(MoneyRuleViolation::class);

    // A scan → a receiving line of this purchase; a serial product needs a real serial, never its SKU.
    expect($receiver->lineFromScan($purchase, 'SC-1')['purchase_item_id'])->toBe($screwLine->id)
        ->and($receiver->lineFromScan($purchase, 'SC-1')['serial'])->toBeNull()
        ->and(fn () => $receiver->lineFromScan($purchase, 'KN-1'))->toThrow(MoneyRuleViolation::class);
});

it('adds a landed cost to what is still in stock and books the used part as cost of sales', function () {
    $purchase = $this->purchases->confirm(eurInvoice($this), $this->admin);
    $implantLine = $purchase->items()->where('product_id', $this->implant->id)->first();
    $screwLine = $purchase->items()->where('product_id', $this->screw->id)->first();
    app(PurchaseReceiveService::class)->receive($purchase, [
        ['purchase_item_id' => $implantLine->id, 'serial' => 'SN-P1', 'expiry_date' => '2030-01-01'],
        ['purchase_item_id' => $implantLine->id, 'serial' => 'SN-P2', 'expiry_date' => '2030-01-01'],
        ['purchase_item_id' => $screwLine->id, 'lot' => 'L9', 'quantity' => 100, 'expiry_date' => '2030-01-01'],
    ], $this->staff);

    // One implant already left our stock (sold) before the customs bill arrived.
    $sold = StockUnit::query()->where('serial', 'SN-P2')->sole();
    app(StockMovementService::class)->moveUnitOut($sold, StockStatus::Sold, MovementType::Sale, $this->admin, $sold, (string) Str::uuid(), 'stock.adjust');

    // 220 USD customs = 200 EUR, by quantity: 102 units → per unit 1.9608 EUR.
    $cost = app(LandedCostService::class)->add($purchase, ['type' => 'customs', 'amount' => '220', 'currency' => 'USD', 'allocation_method' => 'quantity'], $this->admin);

    $implantAlloc = $cost->allocations()->where('purchase_item_id', $implantLine->id)->first();
    expect($cost->allocations()->count())->toBe(2)
        ->and((float) $cost->allocations()->sum('amount'))->toEqual(200.0)
        ->and($implantAlloc->quantity_on_hand)->toBe(1)
        ->and($implantAlloc->quantity_used)->toBe(1)
        ->and((string) StockUnit::query()->where('serial', 'SN-P1')->value('unit_cost'))->toBe('476.9608')
        ->and((string) StockUnit::query()->where('serial', 'SN-P2')->value('unit_cost'))->toBe('475.0000')
        ->and((string) $cost->cogs_amount)->toBe('1.9608')
        ->and(StockBatch::query()->where('lot', 'L9')->where('quantity', '>', 0)->value('unit_cost'))->toEqual('11.4608')
        ->and(StockMovement::query()->where('type', MovementType::CostAdjustment)->count())->toBe(2);
});

it('pays a EUR invoice from a USD account with the realized gain or loss', function () {
    $purchase = $this->purchases->confirm(eurInvoice($this), $this->admin);
    app(SettingsRepository::class)->set('approvals.supplier_payment_threshold_usd', 100000);

    // The euro got cheaper since the invoice (1.10 → 1.05): paying costs fewer dollars → a gain.
    app(FxService::class)->setRate('EUR', 'USD', '1.05', $this->admin);
    $payment = app(SupplierPaymentService::class)->pay($this->supplier, $this->usdBox, ['amount' => '1050', 'method' => 'bank_wire', 'reference' => 'SWIFT-1'], $this->accountant);

    expect($payment->status)->toBe(SupplierPaymentStatus::Posted)
        ->and((string) $this->usdBox->fresh()->current_balance)->toBe('48950.0000')
        ->and((string) $payment->allocations()->value('amount_in_purchase_currency'))->toBe('1000.0000')
        ->and((string) $payment->fx_gain_loss_usd)->toBe('50.0000')
        ->and($purchase->fresh()->payment_status)->toBe(PaymentStatus::Partial)
        ->and($this->party->balances($this->supplier))->toBe(['EUR' => '900.0000']);

    app(SupplierPaymentService::class)->void($payment, 'Bank returned it', $this->admin);
    expect($this->party->balances($this->supplier))->toBe(['EUR' => '1900.0000'])
        ->and($purchase->fresh()->payment_status)->toBe(PaymentStatus::Unpaid)
        ->and((string) $this->usdBox->fresh()->current_balance)->toBe('50000.0000');
});

it('sends a large payment for approval; only someone else can approve it', function () {
    $this->purchases->confirm(eurInvoice($this), $this->admin);
    app(SettingsRepository::class)->set('approvals.supplier_payment_threshold_usd', 1000);

    $payment = app(SupplierPaymentService::class)->pay($this->supplier, $this->usdBox, ['amount' => '2090'], $this->accountant);
    $approval = Approval::query()->sole();

    expect($payment->status)->toBe(SupplierPaymentStatus::PendingApproval)
        ->and((string) $this->usdBox->fresh()->current_balance)->toBe('50000.0000')
        ->and(fn () => app(ApprovalService::class)->approve($approval, $this->accountant))->toThrow(AuthorizationException::class);

    app(ApprovalService::class)->approve($approval, $this->admin, 'OK');

    expect($approval->fresh()->status)->toBe(ApprovalStatus::Approved)
        ->and($payment->fresh()->status)->toBe(SupplierPaymentStatus::Posted)
        ->and((string) $this->usdBox->fresh()->current_balance)->toBe('47910.0000')
        ->and($this->party->balances($this->supplier))->toBe([]);
});

it('cancels only untouched purchases and records supplier credit for returns', function () {
    $purchase = $this->purchases->confirm(eurInvoice($this), $this->admin);
    $this->purchases->cancel($purchase, 'Entered twice', $this->admin);
    expect($purchase->fresh()->status)->toBe(PurchaseStatus::Cancelled)->and($this->party->balances($this->supplier))->toBe([]);

    $second = $this->purchases->confirm(eurInvoice($this, 'NJ-200'), $this->admin);
    app(PurchaseReceiveService::class)->receive($second, [['purchase_item_id' => $second->items()->where('product_id', $this->implant->id)->value('id'), 'serial' => 'SN-R1', 'expiry_date' => '2030-01-01']], $this->staff);
    expect(fn () => $this->purchases->cancel($second, 'x', $this->admin))->toThrow(MoneyRuleViolation::class);

    $return = app(ReturnService::class)->createSupplierReturn($this->supplier, $this->dhk, [['stock_unit_id' => StockUnit::query()->where('serial', 'SN-R1')->value('id'), 'reason' => 'defective']], $this->staff);
    app(ReturnService::class)->sendSupplierReturn($return, $this->staff);
    $credit = app(CreditNoteService::class)->fromSupplierReturn($return, $this->admin, reference: 'NJ-CN-7');

    expect((string) $credit->amount)->toBe('475.0000')
        ->and($return->fresh()->status)->toBe(SupplierReturnStatus::Credited)
        ->and($this->party->balances($this->supplier))->toBe(['EUR' => '1425.0000']);
});
