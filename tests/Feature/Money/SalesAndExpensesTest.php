<?php

use App\Enums\CommissionStatus;
use App\Enums\CustomerType;
use App\Enums\ExpenseStatus;
use App\Enums\MovementType;
use App\Enums\Role;
use App\Enums\SalesInvoiceStatus;
use App\Enums\StockStatus;
use App\Exceptions\Domain\MoneyRuleViolation;
use App\Models\CommissionEntry;
use App\Models\CommissionRule;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\RecurringExpense;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\StockUnit;
use App\Models\SurgeryType;
use App\Services\Clinical\SurgeryService;
use App\Services\Expenses\CommissionService;
use App\Services\Expenses\ExpenseService;
use App\Services\Expenses\RecurringExpenseService;
use App\Services\Money\CashBoxService;
use App\Services\Money\FxService;
use App\Services\Money\PartyLedgerService;
use App\Services\Sales\CustomerPaymentService;
use App\Services\Sales\SalesService;
use App\Services\Settings\SettingsRepository;
use Database\Seeders\InventoryBaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

/*
 * Sales (Module 13), expenses incl. approvals, allocation and recurring (Module 21),
 * credit sales and commissions behind their feature flags (open questions 2 and 5).
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    $this->dhk = branch('DHK');
    $this->ebl = branch('EBL');
    $this->admin = userWithRole(Role::SuperAdmin, [$this->dhk]);
    $this->cashier = userWithRole(Role::Cashier, [$this->dhk]);
    app(FxService::class)->setRate('USD', 'IQD', '1460', $this->admin);
    app(FxService::class)->setRate('EUR', 'USD', '1.10', $this->admin);

    $this->iqdBox = app(CashBoxService::class)->create($this->dhk, ['name' => ['en' => 'Cash'], 'currency' => 'IQD', 'opening_balance' => '1000000'], $this->admin);
    $this->implant = Product::factory()->create(['sku' => 'KN-1', 'selling_price' => '900', 'selling_currency' => 'USD']);
    $this->screw = Product::factory()->lot()->create(['sku' => 'SC-1', 'selling_price' => '20', 'selling_currency' => 'USD']);
    stockIn($this->dhk, $this->admin, [
        ['product_id' => $this->implant->id, 'serial' => 'SN-1', 'unit_cost' => '400'],
        ['product_id' => $this->screw->id, 'lot' => 'L1', 'quantity' => 50, 'unit_cost' => '5'],
    ]);
    $this->category = ExpenseCategory::query()->create(['code' => 'RENT', 'name' => ['en' => 'Rent']]);
});

it('sells in IQD at the converted list price: stock sold, money in, cost kept for profit', function () {
    $invoice = app(SalesService::class)->sell($this->dhk, [
        'currency' => 'IQD',
        'cash_box_id' => $this->iqdBox->id,
        'walk_in_name' => 'Walk-in',
        'items' => [['raw' => 'SN-1'], ['raw' => 'SC-1', 'quantity' => 5]],
    ], $this->cashier);

    expect($invoice->number)->toStartWith('INV')
        ->and($invoice->status)->toBe(SalesInvoiceStatus::Paid)
        ->and((string) $invoice->total)->toBe('1460000.0000') // (900 + 5 × 20) × 1460
        ->and((string) $this->iqdBox->fresh()->current_balance)->toBe('2460000.0000')
        ->and(StockUnit::query()->where('serial', 'SN-1')->value('status'))->toBe(StockStatus::Sold)
        ->and(StockBatch::query()->where('lot', 'L1')->value('quantity'))->toBe(45)
        ->and((string) $invoice->items()->whereNotNull('stock_unit_id')->value('unit_cost'))->toBe('400.0000');

    expect(fn () => app(SalesService::class)->void($invoice, 'Wrong customer', $this->cashier))->toThrow(AuthorizationException::class);
    app(SalesService::class)->void($invoice, 'Wrong customer', $this->admin);

    expect($invoice->fresh()->status)->toBe(SalesInvoiceStatus::Voided)
        ->and(StockUnit::query()->where('serial', 'SN-1')->value('status'))->toBe(StockStatus::InStock)
        ->and((int) StockBatch::query()->where('lot', 'L1')->sum('quantity'))->toBe(50)
        ->and((string) $this->iqdBox->fresh()->current_balance)->toBe('1000000.0000')
        ->and(StockMovement::query()->where('type', MovementType::SaleReturn)->count())->toBe(2);
});

it('refuses credit sales while the flag is off; with it on, payments settle invoices', function () {
    $hospital = Customer::query()->create(['code' => 'HSP-1', 'type' => CustomerType::Hospital, 'name' => ['en' => 'Azadi']]);
    $credit = ['currency' => 'USD', 'customer_id' => $hospital->id, 'payment_terms' => 'credit', 'items' => [['raw' => 'SC-1', 'quantity' => 10]]];

    expect(fn () => app(SalesService::class)->sell($this->dhk, $credit, $this->cashier))->toThrow(MoneyRuleViolation::class);

    app(SettingsRepository::class)->set('features.credit_sales', true);
    $invoice = app(SalesService::class)->sell($this->dhk, $credit, $this->cashier);
    $party = app(PartyLedgerService::class);

    expect($invoice->status)->toBe(SalesInvoiceStatus::Issued)->and($party->balances($hospital))->toBe(['USD' => '200.0000']);

    // 146,000 IQD = 100 USD at the same rate.
    app(CustomerPaymentService::class)->receive($hospital, $this->iqdBox, ['amount' => '146000'], $this->cashier);

    expect($invoice->fresh()->status)->toBe(SalesInvoiceStatus::PartiallyPaid)
        ->and((string) $invoice->fresh()->paid_amount)->toBe('100.0000')
        ->and($party->balances($hospital))->toBe(['USD' => '100.0000']);
});

it('posts expenses, sends large ones for approval and spreads general ones over branches', function () {
    $expenses = app(ExpenseService::class);
    app(SettingsRepository::class)->set('approvals.expense_threshold_usd', 500);

    $small = $expenses->record($this->dhk, ['expense_category_id' => $this->category->id, 'cash_box_id' => $this->iqdBox->id, 'amount' => '146000', 'payee' => 'Landlord'], $this->cashier);
    expect($small->status)->toBe(ExpenseStatus::Posted)
        ->and((string) $small->amount_usd)->toBe('100.0000')
        ->and((string) $this->iqdBox->fresh()->current_balance)->toBe('854000.0000');

    $large = $expenses->record($this->dhk, ['expense_category_id' => $this->category->id, 'cash_box_id' => $this->iqdBox->id, 'amount' => '876000'], $this->cashier);
    expect($large->status)->toBe(ExpenseStatus::PendingApproval)->and((string) $this->iqdBox->fresh()->current_balance)->toBe('854000.0000');

    $headOffice = app(CashBoxService::class)->create(null, ['name' => ['en' => 'HO'], 'currency' => 'USD', 'opening_balance' => '1000'], $this->admin);
    $shared = $expenses->record(null, ['expense_category_id' => $this->category->id, 'cash_box_id' => $headOffice->id, 'amount' => '300', 'allocations' => [$this->dhk->id => 50, $this->ebl->id => 50]], $this->admin);
    expect($shared->is_allocated)->toBeTrue()
        ->and((string) $shared->allocations()->where('branch_id', $this->ebl->id)->value('amount'))->toBe('150.0000')
        ->and(fn () => $expenses->record(null, ['expense_category_id' => $this->category->id, 'cash_box_id' => $headOffice->id, 'amount' => '10', 'allocations' => [$this->dhk->id => 60, $this->ebl->id => 30]], $this->admin))
        ->toThrow(MoneyRuleViolation::class);

    $expenses->void($small, 'Paid twice', $this->admin);
    expect((string) $this->iqdBox->fresh()->current_balance)->toBe('1000000.0000');
});

it('generates recurring expenses once a month', function () {
    RecurringExpense::query()->create([
        'branch_id' => $this->dhk->id, 'expense_category_id' => $this->category->id, 'cash_box_id' => $this->iqdBox->id,
        'amount' => '100000', 'currency' => 'IQD', 'payee' => 'Landlord', 'day_of_month' => 1, 'starts_on' => '2026-01-01',
        'auto_post' => true, 'is_active' => true, 'created_by' => $this->admin->id,
    ]);
    $service = app(RecurringExpenseService::class);

    expect($service->generateDue(now()->setDate(2026, 10, 5)))->toBe(1)
        ->and($service->generateDue(now()->setDate(2026, 10, 20)))->toBe(0)
        ->and($service->generateDue(now()->setDate(2026, 11, 1)))->toBe(1)
        ->and((string) $this->iqdBox->fresh()->current_balance)->toBe('800000.0000');
});

it('accrues doctor commissions only when the feature is on', function () {
    $doctor = userWithRole(Role::Doctor, [$this->dhk]);
    $type = SurgeryType::query()->create(['code' => 'TKA', 'name' => ['en' => 'Knee']]);
    $hospital = Customer::query()->create(['code' => 'HSP-1', 'type' => CustomerType::Hospital, 'name' => ['en' => 'Azadi']]);
    CommissionRule::query()->create(['basis' => 'per_surgery', 'calc_type' => 'fixed', 'value' => '50', 'currency' => 'USD', 'is_active' => true]);
    $surgery = fn (string $serial) => app(SurgeryService::class)->sync([
        'client_uuid' => (string) Str::uuid(), 'branch_id' => $this->dhk->id, 'surgery_type_id' => $type->id, 'customer_id' => $hospital->id,
        'new_patient' => ['full_name' => 'P '.$serial], 'items' => [['raw' => $serial]], 'complete' => true,
    ], $doctor);

    $surgery('SN-1');
    expect(CommissionEntry::query()->count())->toBe(0);

    app(SettingsRepository::class)->set('features.commissions', true);
    stockIn($this->dhk, $this->admin, [['product_id' => $this->implant->id, 'serial' => 'SN-2']]);
    $surgery('SN-2');

    $entry = CommissionEntry::query()->sole();
    expect((string) $entry->amount)->toBe('50.0000')->and($entry->doctor_id)->toBe($doctor->id);

    $usd = app(CashBoxService::class)->create($this->dhk, ['name' => ['en' => 'USD'], 'currency' => 'USD', 'opening_balance' => '500'], $this->admin);
    app(CommissionService::class)->approve($entry, $this->admin);
    app(CommissionService::class)->pay([$entry->id], $usd, $this->admin);

    expect($entry->fresh()->status)->toBe(CommissionStatus::Paid)->and((string) $usd->fresh()->current_balance)->toBe('450.0000');
});
