<?php

use App\Enums\BillingStatus;
use App\Enums\CustomerType;
use App\Enums\InvoiceKind;
use App\Enums\MoneyTransactionType;
use App\Enums\PaymentTerms;
use App\Enums\Role;
use App\Enums\SalesInvoiceStatus;
use App\Enums\SurgeryStatus;
use App\Exceptions\Domain\MoneyRuleViolation;
use App\Exceptions\Domain\StockRuleViolation;
use App\Models\Customer;
use App\Models\MoneyTransaction;
use App\Models\Patient;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\SurgeryType;
use App\Services\Clinical\SurgeryService;
use App\Services\Money\CashBoxService;
use App\Services\Money\FxService;
use App\Services\Sales\InvoiceService;
use App\Services\Sales\SalesService;
use Database\Seeders\InventoryBaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

/*
 * Invoices for sales and surgeries: unpaid until marked paid into a box (only then the money
 * counts), editable while unpaid, and "not paid" again with a reason.
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    $this->dhk = branch('DHK');
    $this->admin = userWithRole(Role::SuperAdmin, [$this->dhk]);
    $this->doctor = userWithRole(Role::Doctor, [$this->dhk]);
    $this->cashier = userWithRole(Role::Cashier, [$this->dhk]);
    $this->accountant = userWithRole(Role::Accountant, [$this->dhk]);
    app(FxService::class)->setRate('USD', 'IQD', '1460', $this->admin);
    app(FxService::class)->setRate('EUR', 'USD', '1.10', $this->admin);

    $this->usdBox = app(CashBoxService::class)->create($this->dhk, ['name' => ['en' => 'Cash USD'], 'currency' => 'USD', 'opening_balance' => '100'], $this->admin);
    $this->hospital = Customer::query()->create(['code' => 'HSP-1', 'type' => CustomerType::Hospital, 'name' => ['en' => 'Azadi']]);
    $this->type = SurgeryType::query()->create(['code' => 'THA', 'name' => ['en' => 'Total hip']]);
    $this->implant = Product::factory()->create(['sku' => 'HP-1', 'selling_price' => '900', 'selling_currency' => 'USD']);
    stockIn($this->dhk, $this->admin, [
        ['product_id' => $this->implant->id, 'serial' => 'SN-1'],
        ['product_id' => $this->implant->id, 'serial' => 'SN-2'],
        ['product_id' => $this->implant->id, 'serial' => 'SN-3'],
    ]);
});

function feeSurgery($test, array $overrides = []): array
{
    return $overrides + [
        'client_uuid' => (string) Str::uuid(),
        'branch_id' => $test->dhk->id,
        'surgery_date' => now()->toDateString(),
        'surgery_type_id' => $test->type->id,
        'customer_id' => $test->hospital->id,
        'fee_amount' => '1500',
        'fee_currency' => 'USD',
        'items' => [['raw' => 'SN-1']],
        'complete' => true,
    ];
}

it('completes a surgery without a patient name, and its fee becomes an unpaid invoice', function () {
    $surgery = app(SurgeryService::class)->sync(feeSurgery($this), $this->doctor);
    $invoice = SalesInvoice::query()->where('surgery_id', $surgery->id)->first();

    expect($surgery->status)->toBe(SurgeryStatus::Completed)
        ->and($surgery->patient_id)->toBeNull()
        ->and(Patient::query()->count())->toBe(0)
        ->and($surgery->billing_status)->toBe(BillingStatus::Billed)
        ->and($invoice->kind)->toBe(InvoiceKind::Surgery)
        ->and($invoice->status)->toBe(SalesInvoiceStatus::Issued)
        ->and($invoice->payment_terms)->toBe(PaymentTerms::Later)
        ->and((string) $invoice->total)->toBe('1500.0000')
        ->and($invoice->customer_id)->toBe($this->hospital->id)
        // Nothing in the box yet.
        ->and((string) $this->usdBox->fresh()->current_balance)->toBe('100.0000');
});

it('keeps a draft when the fee cannot be read', function () {
    $surgery = app(SurgeryService::class)->sync(feeSurgery($this, ['fee_amount' => 'abc']), $this->doctor);

    expect($surgery->status)->toBe(SurgeryStatus::Draft)
        ->and($surgery->problems)->toContain(__('The surgery fee must be a number above zero.'));
});

it('marks an invoice paid into a box, and not paid again with a reason', function () {
    $surgery = app(SurgeryService::class)->sync(feeSurgery($this), $this->doctor);
    $invoice = SalesInvoice::query()->where('surgery_id', $surgery->id)->firstOrFail();
    $invoices = app(InvoiceService::class);

    // A doctor cannot mark invoices paid.
    expect(fn () => $invoices->markPaid($invoice, $this->usdBox, $this->doctor))->toThrow(AuthorizationException::class);

    $paid = $invoices->markPaid($invoice, $this->usdBox, $this->cashier);

    expect($paid->status)->toBe(SalesInvoiceStatus::Paid)
        ->and($paid->paid_by)->toBe($this->cashier->id)
        ->and($paid->paid_cash_box_id)->toBe($this->usdBox->id)
        ->and((string) $this->usdBox->fresh()->current_balance)->toBe('1600.0000');

    // A paid invoice is not edited.
    expect(fn () => $invoices->update($paid, ['amount' => '1200', 'currency' => 'USD'], $this->accountant))->toThrow(MoneyRuleViolation::class);

    expect(fn () => $invoices->unmarkPaid($paid, '', $this->cashier))->toThrow(MoneyRuleViolation::class);
    $unpaid = $invoices->unmarkPaid($paid, 'Cheque bounced', $this->cashier);

    expect($unpaid->status)->toBe(SalesInvoiceStatus::Issued)
        ->and($unpaid->paid_at)->toBeNull()
        ->and((string) $this->usdBox->fresh()->current_balance)->toBe('100.0000')
        ->and(MoneyTransaction::query()->where('type', MoneyTransactionType::Reversal)->count())->toBe(1);

    // Edit the unpaid invoice: the surgery fee follows.
    $edited = $invoices->update($unpaid, ['customer_id' => $this->hospital->id, 'amount' => '1200', 'currency' => 'USD', 'notes' => 'Agreed discount'], $this->accountant);

    expect((string) $edited->total)->toBe('1200.0000')
        ->and((string) $surgery->fresh()->fee_amount)->toBe('1200.0000');

    // Paid again, then voided: only the standing receipt is reversed.
    $invoices->markPaid($edited, $this->usdBox, $this->cashier);
    app(SalesService::class)->void($edited->fresh(), 'Billed by mistake', $this->admin);

    expect($edited->fresh()->status)->toBe(SalesInvoiceStatus::Voided)
        ->and($surgery->fresh()->billing_status)->toBe(BillingStatus::NotBilled)
        ->and((string) $this->usdBox->fresh()->current_balance)->toBe('100.0000');
});

it('refuses a box in another currency', function () {
    $surgery = app(SurgeryService::class)->sync(feeSurgery($this, ['fee_amount' => '2000000', 'fee_currency' => 'IQD']), $this->doctor);
    $invoice = SalesInvoice::query()->where('surgery_id', $surgery->id)->firstOrFail();

    expect(fn () => app(InvoiceService::class)->markPaid($invoice, $this->usdBox, $this->cashier))->toThrow(MoneyRuleViolation::class);
});

it('sells on an unpaid invoice: stock leaves, the money only comes when marked paid', function () {
    $invoice = app(SalesService::class)->sell($this->dhk, [
        'currency' => 'USD',
        'payment_terms' => PaymentTerms::Later->value,
        'walk_in_name' => 'Dr. Hawre',
        'items' => [['raw' => 'SN-2'], ['raw' => 'SN-3']],
    ], $this->cashier);

    expect($invoice->status)->toBe(SalesInvoiceStatus::Issued)
        ->and($invoice->kind)->toBe(InvoiceKind::Sale)
        ->and((string) $invoice->total)->toBe('1800.0000')
        ->and((string) $this->usdBox->fresh()->current_balance)->toBe('100.0000');

    // Edit the line amounts and discount while unpaid.
    $lines = $invoice->items()->orderBy('id')->pluck('id')->all();
    $edited = app(InvoiceService::class)->update($invoice, ['walk_in_name' => 'Dr. Hawre', 'lines' => [$lines[0] => '850', $lines[1] => '850'], 'discount' => '100'], $this->accountant);

    expect((string) $edited->subtotal)->toBe('1700.0000')
        ->and((string) $edited->total)->toBe('1600.0000');

    app(InvoiceService::class)->markPaid($edited, $this->usdBox, $this->cashier);
    expect((string) $this->usdBox->fresh()->current_balance)->toBe('1700.0000');
});

it('marks a sale paid at once not paid: its cash receipt is reversed', function () {
    $invoice = app(SalesService::class)->sell($this->dhk, [
        'currency' => 'USD',
        'cash_box_id' => $this->usdBox->id,
        'walk_in_name' => 'Walk-in',
        'items' => [['raw' => 'SN-2']],
    ], $this->cashier);

    expect($invoice->status)->toBe(SalesInvoiceStatus::Paid)
        ->and((string) $this->usdBox->fresh()->current_balance)->toBe('1000.0000');

    $unpaid = app(InvoiceService::class)->unmarkPaid($invoice, 'Customer will pay next week', $this->cashier);

    expect($unpaid->status)->toBe(SalesInvoiceStatus::Issued)
        ->and($unpaid->payment_terms)->toBe(PaymentTerms::Later)
        ->and((string) $this->usdBox->fresh()->current_balance)->toBe('100.0000');
});

it('makes an invoice for a completed surgery logged without a fee', function () {
    $surgery = app(SurgeryService::class)->sync(feeSurgery($this, ['fee_amount' => null]), $this->doctor);

    expect(SalesInvoice::query()->where('surgery_id', $surgery->id)->exists())->toBeFalse();

    $invoice = app(InvoiceService::class)->billSurgery($surgery, '900', 'USD', $this->accountant);

    expect($invoice->kind)->toBe(InvoiceKind::Surgery)
        ->and((string) $invoice->total)->toBe('900.0000')
        ->and(fn () => app(InvoiceService::class)->billSurgery($surgery, '900', 'USD', $this->accountant))->toThrow(MoneyRuleViolation::class);
});

it('cancels a surgery: an unpaid invoice is voided, a paid one must be marked not paid first', function () {
    $surgery = app(SurgeryService::class)->sync(feeSurgery($this), $this->doctor);
    $invoice = SalesInvoice::query()->where('surgery_id', $surgery->id)->firstOrFail();
    app(InvoiceService::class)->markPaid($invoice, $this->usdBox, $this->cashier);

    expect(fn () => app(SurgeryService::class)->cancel($surgery, 'Wrong patient', $this->admin))->toThrow(StockRuleViolation::class);

    app(InvoiceService::class)->unmarkPaid($invoice->fresh(), 'Refunded', $this->cashier);
    app(SurgeryService::class)->cancel($surgery->fresh(), 'Wrong patient', $this->admin);

    expect($invoice->fresh()->status)->toBe(SalesInvoiceStatus::Voided)
        ->and($surgery->fresh()->status)->toBe(SurgeryStatus::Cancelled);
});
