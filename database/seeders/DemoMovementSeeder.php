<?php

namespace Database\Seeders;

use App\Enums\CustomerType;
use App\Enums\LocationType;
use App\Enums\RequestPriority;
use App\Enums\Role;
use App\Enums\StockCountScope;
use App\Enums\StockStatus;
use App\Enums\WriteOffReason;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Movement\ReturnService;
use App\Services\Movement\StockCountService;
use App\Services\Movement\StockRequestService;
use App\Services\Movement\TransferService;
use App\Services\Movement\WriteOffService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Demo movement documents (local/staging only), made through the real services so the
 * ledger is consistent: transfers in every state, a stock request, a write-off, returns,
 * stock counts and the issues they raise.
 */
class DemoMovementSeeder extends Seeder
{
    public function run(TransferService $transfers, StockRequestService $requests, WriteOffService $writeOffs, ReturnService $returns, StockCountService $counts): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo documents are never created in production.');
        }

        $admin = $this->user('superadmin', Role::SuperAdmin);
        $dhk = Branch::query()->where('code', 'DHK')->firstOrFail();
        $ebl = Branch::query()->where('code', 'EBL')->firstOrFail();
        $bgw = Branch::query()->where('code', 'BGW')->firstOrFail();
        $staff = User::query()->where('username', 'staff')->first() ?? $admin;
        $staffBgw = User::query()->where('username', 'staff.bgw')->first() ?? $admin;

        $this->customers($admin);

        // 1. On its way DHK → BGW: two implants and a box of screws.
        $transfer = $transfers->createDraft($dhk, $bgw, $staff, 'Weekly top-up');
        foreach ($this->units($dhk, 2) as $unit) {
            $transfers->addUnit($transfer, $unit, $staff);
        }
        $transfers->addBatch($transfer, $this->batch($dhk, 'SC-COR-3530'), 5, $staff);
        $transfers->send($transfer, $staff);

        // 2. EBL → DHK received with one unit missing: an issue for the admin.
        $short = $transfers->createDraft($ebl, $dhk, $admin);
        $sent = $this->units($ebl, 2);
        foreach ($sent as $unit) {
            $transfers->addUnit($short, $unit, $admin);
        }
        $transfers->send($short, $admin);
        $transfers->receive($short, [$sent[0]->id], [], $staff);

        // 3. A draft being prepared DHK → EBL.
        $draft = $transfers->createDraft($dhk, $ebl, $staff);
        $transfers->addBatch($draft, $this->batch($dhk, 'KW-16'), 10, $staff);

        // 4. BGW asks for screws and cement (urgent, waiting for approval).
        $requests->create($bgw, [
            ['product_id' => $this->product('SC-CAN-7050')->id, 'quantity' => 10],
            ['product_id' => $this->product('CM-40G')->id, 'quantity' => 4, 'note' => 'Two knee cases next week'],
        ], $staffBgw, $dhk, RequestPriority::Urgent, now()->addDays(3)->toDateString(), 'Surgery list is full');

        // 5. A damaged implant reported in DHK (waits for approval).
        $writeOffs->report($dhk, WriteOffReason::Damaged, [['stock_unit_id' => $this->units($dhk, 1)[0]->id]], $staff, 'Sterile packaging torn');

        // 6. A hospital returns unused screws to DHK.
        $returns->receiveFromCustomer($dhk, [[
            'product_id' => $this->product('SC-COR-3530')->id,
            'lot' => 'RET-'.now()->format('ymd'),
            'expiry_date' => now()->addMonths(14)->toDateString(),
            'quantity' => 4,
            'reason' => 'unused',
            'condition' => 'good',
        ]], $staff, Customer::query()->where('code', 'HSP-AZADI')->first(), 'Surgery postponed');

        // 7. A defective implant goes back to its supplier (draft).
        $supplier = Supplier::query()->orderBy('id')->first();
        if ($supplier !== null) {
            $returns->createSupplierReturn($supplier, $dhk, [['stock_unit_id' => $this->units($dhk, 1)[0]->id, 'reason' => 'defective']], $staff, 'Scratched on arrival');
        }

        // 8. Stock counts: screws in DHK half-scanned; EBL submitted with differences.
        $screws = Category::query()->where('code', 'SCREW')->first();
        $running = $counts->start($dhk, $screws !== null ? StockCountScope::Category : StockCountScope::Full, $staff, $screws?->id);
        $firstLot = $running->lines()->whereNotNull('stock_batch_id')->first();
        if ($firstLot !== null) {
            $counts->setCounted($firstLot, $firstLot->expected_qty, $staff);
        }

        $submitted = $counts->start($ebl, StockCountScope::Full, $admin);
        foreach ($submitted->lines()->with('stockUnit')->whereNotNull('stock_unit_id')->orderBy('id')->offset(1)->limit(1000)->get() as $line) {
            $counts->recordScan($submitted, (string) Str::uuid(), $line->stockUnit->serial, $admin);
        }
        foreach ($submitted->lines()->whereNotNull('stock_batch_id')->get() as $i => $line) {
            $counts->setCounted($line, $i === 0 ? max(0, $line->expected_qty - 2) : $line->expected_qty, $admin);
        }
        $counts->submit($submitted, $admin);
    }

    private function customers(User $admin): void
    {
        foreach ([
            ['HSP-AZADI', CustomerType::Hospital, ['en' => 'Azadi Teaching Hospital', 'ar' => 'مستشفى آزادي التعليمي', 'ckb' => 'نەخۆشخانەی فێرکاری ئازادی'], ['en' => 'Duhok', 'ar' => 'دهوك', 'ckb' => 'دهۆک'], '+964 750 000 0001'],
            ['HSP-RIZGARY', CustomerType::Hospital, ['en' => 'Rizgary Hospital', 'ar' => 'مستشفى رزگاري', 'ckb' => 'نەخۆشخانەی ڕزگاری'], ['en' => 'Erbil', 'ar' => 'أربيل', 'ckb' => 'هەولێر'], '+964 750 000 0002'],
            ['CLN-NOOR', CustomerType::Clinic, ['en' => 'Al-Noor Orthopedic Clinic', 'ar' => 'عيادة النور للعظام', 'ckb' => 'نۆرینگەی ئێسکی النور'], ['en' => 'Baghdad', 'ar' => 'بغداد', 'ckb' => 'بەغدا'], '+964 770 000 0003'],
        ] as [$code, $type, $name, $city, $phone]) {
            Customer::query()->firstOrCreate(['code' => $code], [
                'type' => $type, 'name' => $name, 'city' => $city, 'phone' => $phone, 'created_by' => $admin->id,
            ]);
        }
    }

    /** @return list<StockUnit> serial units on a shelf, not on any document yet */
    private function units(Branch $branch, int $count): array
    {
        return StockUnit::query()
            ->where('branch_id', $branch->id)
            ->where('status', StockStatus::InStock)
            ->where(fn ($q) => $q->whereNull('expiry_date')->orWhere('expiry_date', '>', now()->addMonths(3)->toDateString()))
            ->whereDoesntHave('transferItems')
            ->whereDoesntHave('writeOffItems')
            ->orderBy('id')
            ->limit($count)
            ->get()
            ->all();
    }

    private function batch(Branch $branch, string $sku): StockBatch
    {
        return StockBatch::query()
            ->where('branch_id', $branch->id)
            ->where('location_type', LocationType::Shelf)
            ->where('product_id', $this->product($sku)->id)
            ->where('quantity', '>=', 10)
            ->where(fn ($q) => $q->whereNull('expiry_date')->orWhere('expiry_date', '>', now()->toDateString()))
            ->orderBy('expiry_date')
            ->firstOrFail();
    }

    private function product(string $sku): Product
    {
        return Product::query()->where('sku', $sku)->firstOrFail();
    }

    private function user(string $username, Role $fallbackRole): User
    {
        return User::query()->where('username', $username)->first()
            ?? User::query()->whereHas('roles', fn ($q) => $q->where('name', $fallbackRole->value))->firstOrFail();
    }
}
