<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Enums\SurgeryDoctorRole;
use App\Enums\SurgeryStatus;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\RecurringExpense;
use App\Models\StockBatch;
use App\Models\Supplier;
use App\Models\Surgery;
use App\Models\User;
use App\Services\Expenses\ExpenseService;
use App\Services\Money\BoxTransferService;
use App\Services\Money\CashBoxService;
use App\Services\Money\CashClosingService;
use App\Services\Money\CurrencyExchangeService;
use App\Services\Money\FxService;
use App\Services\Purchasing\LandedCostService;
use App\Services\Purchasing\PurchaseReceiveService;
use App\Services\Purchasing\PurchaseService;
use App\Services\Purchasing\SupplierPaymentService;
use App\Services\Sales\InvoiceService;
use App\Services\Sales\SalesService;
use App\Services\Users\TechnicianService;
use Brick\Math\BigDecimal;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Demo money data (local/staging only), made through the real services: exchange rates,
 * cash and bank boxes, supplier invoices (received, costed, partly paid, one overdue),
 * a large payment waiting for approval, a cash sale, expenses (one split, one waiting),
 * a recurring rent, a currency exchange, a transfer on its way and a closing with a gap.
 */
class DemoMoneySeeder extends Seeder
{
    public function run(
        FxService $fx,
        CashBoxService $boxes,
        PurchaseService $purchases,
        PurchaseReceiveService $receiving,
        LandedCostService $landed,
        SupplierPaymentService $payments,
        SalesService $sales,
        ExpenseService $expenses,
        CurrencyExchangeService $exchanges,
        BoxTransferService $transfers,
        CashClosingService $closings,
    ): void {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo money data is never created in production.');
        }

        if (CashBox::query()->exists()) {
            return;
        }

        $admin = User::query()->where('username', 'superadmin')->first()
            ?? User::query()->whereHas('roles', fn ($q) => $q->where('name', Role::SuperAdmin->value))->firstOrFail();
        $accountant = User::query()->where('username', 'accountant')->first() ?? $admin;
        $cashier = User::query()->where('username', 'cashier')->first() ?? $admin;
        Auth::setUser($admin);

        $dhk = Branch::query()->where('code', 'DHK')->firstOrFail();
        $ebl = Branch::query()->where('code', 'EBL')->firstOrFail();

        // Rates (P4-1): USD pivot.
        $fx->setRate('USD', 'IQD', '1460', $admin, 'Market rate');
        $fx->setRate('EUR', 'USD', '1.08', $admin, 'ECB reference');

        // Boxes (P4-4).
        $bank = $boxes->create(null, ['name' => ['en' => 'Head office bank', 'ar' => 'بنك المكتب الرئيسي', 'ckb' => 'بانکی نووسینگەی سەرەکی'], 'type' => 'bank', 'currency' => 'USD', 'bank_name' => 'Demo Bank', 'account_number' => '0012-3456-78', 'opening_balance' => '60000'], $admin);
        $dhkUsd = $boxes->create($dhk, ['name' => ['en' => 'Duhok cash USD', 'ar' => 'صندوق دهوك دولار', 'ckb' => 'سندوقی دهۆک دۆلار'], 'currency' => 'USD', 'opening_balance' => '4500'], $admin);
        $dhkIqd = $boxes->create($dhk, ['name' => ['en' => 'Duhok cash IQD', 'ar' => 'صندوق دهوك دينار', 'ckb' => 'سندوقی دهۆک دینار'], 'currency' => 'IQD', 'opening_balance' => '3650000'], $admin);
        $eblUsd = $boxes->create($ebl, ['name' => ['en' => 'Erbil cash USD', 'ar' => 'صندوق أربيل دولار', 'ckb' => 'سندوقی هەولێر دۆلار'], 'currency' => 'USD', 'opening_balance' => '2000'], $admin);

        // Purchases (P4-7…P4-9).
        $novaJoint = Supplier::query()->where('code', 'NVJ')->first();
        $boneTech = Supplier::query()->where('code', 'BTS')->first();
        $kurdmed = Supplier::query()->where('code', 'KMD')->first();
        $tray = Product::query()->where('sku', 'KN-TIB-4')->first();
        $femoral = Product::query()->where('sku', 'KN-FEM-4')->first();
        $cement = Product::query()->where('sku', 'CM-40G')->first();
        $stem = Product::query()->where('sku', 'HP-STM-12')->first();
        $screw = Product::query()->where('sku', 'SC-COR-3530')->first();

        if ($novaJoint && $boneTech && $kurdmed && $tray && $femoral && $cement && $stem && $screw) {
            $nvj = $purchases->create($novaJoint, $dhk, [
                'supplier_invoice_number' => 'NJ-2026-0418',
                'invoice_date' => now()->subDays(20)->toDateString(),
                'currency' => 'EUR',
                'discount' => '150',
                'lines' => [
                    ['product_id' => $tray->id, 'quantity' => 2, 'unit_price' => '720'],
                    ['product_id' => $femoral->id, 'quantity' => 2, 'unit_price' => '1050'],
                    ['product_id' => $cement->id, 'quantity' => 20, 'unit_price' => '48', 'expected_lot' => 'CM-L2611', 'expected_expiry' => now()->addMonths(18)->toDateString()],
                ],
            ], $admin);
            $purchases->confirm($nvj, $admin);
            $items = $nvj->items()->get()->keyBy('product_id');
            $receiving->receive($nvj, [
                ['purchase_item_id' => $items[$tray->id]->id, 'serial' => 'NJ-TIB4-90001', 'lot' => 'NJ-L0926', 'expiry_date' => now()->addYears(4)->toDateString()],
                ['purchase_item_id' => $items[$tray->id]->id, 'serial' => 'NJ-TIB4-90002', 'lot' => 'NJ-L0926', 'expiry_date' => now()->addYears(4)->toDateString()],
                ['purchase_item_id' => $items[$femoral->id]->id, 'serial' => 'NJ-FEM4-90001', 'lot' => 'NJ-L0926', 'expiry_date' => now()->addYears(4)->toDateString()],
                ['purchase_item_id' => $items[$cement->id]->id, 'quantity' => 20],
            ], $admin, notes: 'Second femoral component on back-order');
            $landed->add($nvj, ['type' => 'shipping', 'amount' => '240', 'currency' => 'EUR', 'allocation_method' => 'value', 'note' => 'DHL Frankfurt → Erbil'], $admin);
            $landed->add($nvj, ['type' => 'customs', 'amount' => '350000', 'currency' => 'IQD', 'allocation_method' => 'value', 'note' => 'Ibrahim Khalil border'], $admin);
            $payments->pay($novaJoint, $bank, ['amount' => '2500', 'method' => 'bank_wire', 'reference' => 'SWIFT-77821', 'paid_at' => now()->subDays(5)->toDateString()], $accountant);

            // Overdue: invoice 60 days old on 45-day terms, nothing paid.
            $bts = $purchases->create($boneTech, $dhk, [
                'supplier_invoice_number' => 'BT-55190',
                'invoice_date' => now()->subDays(60)->toDateString(),
                'currency' => 'USD',
                'lines' => [['product_id' => $stem->id, 'quantity' => 3, 'unit_price' => '880']],
            ], $admin);
            $purchases->confirm($bts, $admin);

            // A large payment waits for approval (threshold 5,000 USD).
            $big = $purchases->create($boneTech, $ebl, [
                'supplier_invoice_number' => 'BT-55304',
                'invoice_date' => now()->subDays(10)->toDateString(),
                'currency' => 'USD',
                'lines' => [['product_id' => $stem->id, 'quantity' => 8, 'unit_price' => '880']],
            ], $admin);
            $purchases->confirm($big, $admin);
            $payments->pay($boneTech, $bank, ['amount' => '7040', 'method' => 'bank_wire', 'reference' => 'SWIFT-77930', 'allocations' => [$big->id => '7040']], $accountant);

            // A draft still being typed.
            $purchases->create($kurdmed, $dhk, [
                'supplier_invoice_number' => 'KM-1187',
                'invoice_date' => now()->toDateString(),
                'currency' => 'USD',
                'lines' => [['product_id' => $screw->id, 'quantity' => 100, 'unit_price' => '9.5']],
            ], $admin);

            // A cash sale of cement from the shelf it just arrived on (P4-12).
            if (StockBatch::query()->where('branch_id', $dhk->id)->where('product_id', $cement->id)->where('quantity', '>=', 2)->exists()) {
                $sales->sell($dhk, [
                    'currency' => 'USD',
                    'walk_in_name' => 'Dr. Hawre (private clinic)',
                    'payment_terms' => 'cash',
                    'cash_box_id' => $dhkUsd->id,
                    'items' => [['product_id' => $cement->id, 'quantity' => 2, 'unit_price' => '70']],
                ], $cashier->canInBranch('sales.create', $dhk->id) ? $cashier : $admin);
            }
        }

        // Expenses (P4-14).
        $category = fn (string $code) => ExpenseCategory::query()->where('code', $code)->value('id');
        $expenses->record($dhk, ['expense_category_id' => $category('RENT'), 'cash_box_id' => $dhkUsd->id, 'amount' => '850', 'expense_date' => now()->startOfMonth()->toDateString(), 'payee' => 'Building owner', 'description' => 'Duhok store rent'], $admin);
        $expenses->record($dhk, ['expense_category_id' => $category('FUEL'), 'cash_box_id' => $dhkIqd->id, 'amount' => '175000', 'expense_date' => now()->subDays(2)->toDateString(), 'payee' => 'Generator', 'description' => 'Generator subscription'], $admin);
        $expenses->record(null, ['expense_category_id' => $category('LICENSE'), 'cash_box_id' => $bank->id, 'amount' => '600', 'description' => 'Ministry of Health import licence', 'allocations' => [$dhk->id => '60', $ebl->id => '40']], $admin);
        $expenses->record($ebl, ['expense_category_id' => $category('MAINT'), 'cash_box_id' => $eblUsd->id, 'amount' => '1400', 'payee' => 'Cold-room technician', 'description' => 'Air-conditioning for the store room'], $admin);

        RecurringExpense::query()->create([
            'branch_id' => $dhk->id,
            'expense_category_id' => $category('RENT'),
            'cash_box_id' => $dhkUsd->id,
            'amount' => '850',
            'currency' => 'USD',
            'payee' => 'Building owner',
            'description' => 'Duhok store rent',
            'day_of_month' => 1,
            'starts_on' => now()->addMonthNoOverflow()->startOfMonth()->toDateString(),
            'auto_post' => true,
            'created_by' => $admin->id,
        ]);

        // Own money exchanged, a transfer on its way, a closing with a small gap (P4-5, P4-6).
        $exchanges->exchange($dhkUsd, $dhkIqd, '500', '1465', $admin, 'Exchange shop in Duhok bazaar');
        $transfers->send($dhkUsd, $eblUsd, '300', $admin, 'Change float for Erbil');

        $this->technicianWork($admin, $dhkUsd);
        $closing = $closings->start($eblUsd, $admin);
        $closings->submit($closing, (string) BigDecimal::of($eblUsd->fresh()->current_balance)->minus(5), $admin, 'Five dollars short — rechecking receipts');
    }

    /**
     * Two technicians on the completed demo surgeries, each surgery billed: one invoice paid,
     * the other still waiting for the money.
     */
    private function technicianWork(User $admin, CashBox $box): void
    {
        $technicians = app(TechnicianService::class);
        $dhk = (int) $box->branch_id;
        $karwan = $technicians->create(['name' => 'Karwan Hasan', 'phone' => '+964 750 000 0201', 'branches' => [$dhk]], $admin);
        $rebin = $technicians->create(['name' => 'Rebin Saeed', 'phone' => '+964 750 000 0202', 'branches' => [$dhk]], $admin);
        $invoices = app(InvoiceService::class);
        $completed = Surgery::query()->where('status', SurgeryStatus::Completed)->orderBy('surgery_date')->get();

        foreach ($completed as $i => $surgery) {
            $team = $i === 0 ? [$karwan, $rebin] : [$karwan];

            foreach ($team as $technician) {
                $surgery->doctors()->syncWithoutDetaching([$technician->id => ['role' => SurgeryDoctorRole::Technician->value]]);
            }

            $invoice = $invoices->billSurgery($surgery, $i === 0 ? '1800' : '2200', 'USD', $admin);

            if ($i === 0) {
                $invoices->markPaid($invoice, $box, $admin);
            }
        }
    }
}
