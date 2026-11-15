<?php

use App\Enums\CustomerType;
use App\Enums\Role;
use App\Filament\Admin\Widgets\InvoicesOverview;
use App\Filament\Admin\Widgets\UnpaidInvoices;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\SurgeryType;
use App\Services\Clinical\SurgeryProductPicker;
use App\Services\Clinical\SurgeryService;
use App\Services\Money\CashBoxService;
use App\Services\Money\FxService;
use App\Services\Reports\InvoicesReport;
use App\Services\Reports\ReportContext;
use App\Services\Sales\InvoiceService;
use Database\Seeders\InventoryBaseSeeder;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * Paid and not paid on the dashboard and in the Invoices report: who owes, for what, the
 * doctor. And choosing surgery products by category.
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    $this->dhk = branch('DHK');
    $this->admin = userWithRole(Role::SuperAdmin, [$this->dhk], ['name' => 'Dr. Lead']);
    app(FxService::class)->setRate('USD', 'IQD', '1460', $this->admin);
    app(FxService::class)->setRate('EUR', 'USD', '1.10', $this->admin);
    $this->box = app(CashBoxService::class)->create($this->dhk, ['name' => ['en' => 'Cash USD'], 'currency' => 'USD', 'opening_balance' => '0'], $this->admin);
    $this->hospital = Customer::query()->create(['code' => 'HSP-1', 'type' => CustomerType::Hospital, 'name' => ['en' => 'Azadi']]);
    $this->type = SurgeryType::query()->create(['code' => 'TKA', 'name' => ['en' => 'Total knee']]);
    $this->knee = Product::factory()->create(['sku' => 'KN-1', 'name' => ['en' => 'Knee tray']]);
    stockIn($this->dhk, $this->admin, [
        ['product_id' => $this->knee->id, 'serial' => 'SN-1'],
        ['product_id' => $this->knee->id, 'serial' => 'SN-2'],
    ]);

    $surgeries = app(SurgeryService::class);
    foreach (['SN-1' => '1000', 'SN-2' => '700'] as $serial => $fee) {
        $surgeries->sync([
            'client_uuid' => (string) Str::uuid(), 'branch_id' => $this->dhk->id, 'surgery_date' => now()->toDateString(),
            'surgery_type_id' => $this->type->id, 'customer_id' => $this->hospital->id,
            'new_patient' => ['full_name' => 'Patient '.$serial], 'fee_amount' => $fee, 'fee_currency' => 'USD',
            'items' => [['raw' => $serial]], 'complete' => true,
        ], $this->admin);
    }

    $paid = SalesInvoice::query()->where('total', '1000')->firstOrFail();
    app(InvoiceService::class)->markPaid($paid, $this->box, $this->admin);
});

it('reports each invoice with who owes, what for, the doctor, paid and still owed', function () {
    $report = app(InvoicesReport::class);
    $rows = $report->rows(ReportContext::make($report, $this->admin, ['group' => 'invoice']));

    expect($rows)->toHaveCount(2);
    $open = collect($rows)->firstWhere('owed', '700.00');
    expect($open['buyer'])->toBe('Azadi · Patient SN-2')
        ->and($open['for'])->toContain('Total knee')
        ->and($open['doctor'])->toBe('Dr. Lead')
        ->and($open['status'])->toBe(__('Not paid'));

    $byStatus = $report->rows(ReportContext::make($report, $this->admin, ['group' => 'status']));
    expect(collect($byStatus)->pluck('owed', 'label')->all())->toEqual([__('Not paid') => '700.00', __('Paid') => '0.00']);

    $byDoctor = $report->rows(ReportContext::make($report, $this->admin, ['group' => 'doctor']));
    expect($byDoctor[0])->toMatchArray(['label' => 'Dr. Lead', 'invoices' => 2, 'total' => '1700.00', 'paid' => '1000.00', 'owed' => '700.00']);
});

it('shows paid and not paid on the dashboard', function () {
    $this->actingAs($this->admin);

    Livewire::test(InvoicesOverview::class)->assertOk()->assertSee('USD 1,000.00')->assertSee('USD 700.00');
    Livewire::test(UnpaidInvoices::class)->assertOk()->assertSee('Azadi')->assertSee('Dr. Lead')->assertDontSee('Patient SN-1');
});

it('lists surgery products by category, with their serials', function () {
    $implants = Category::query()->create(['code' => 'IMPL-T', 'name' => ['en' => 'Implants']]);
    $knee = Category::query()->create(['code' => 'KNEE-T', 'parent_id' => $implants->id, 'name' => ['en' => 'Knee']]);
    $shoulder = Category::query()->create(['code' => 'SHLD-T', 'parent_id' => $implants->id, 'name' => ['en' => 'Shoulder']]);
    $this->knee->forceFill(['category_id' => $knee->id])->save();
    stockIn($this->dhk, $this->admin, [['product_id' => $this->knee->id, 'serial' => 'SN-3']]);
    $picker = app(SurgeryProductPicker::class);

    expect(array_keys($picker->productOptions($this->dhk->id, $knee->id)))->toBe([$this->knee->id])
        // A parent category includes its children.
        ->and(array_keys($picker->productOptions($this->dhk->id, $implants->id)))->toBe([$this->knee->id])
        ->and($picker->productOptions($this->dhk->id, $shoulder->id))->toBe([])
        // SN-1 and SN-2 were used: only SN-3 is left to pick.
        ->and(array_values(array_map(fn ($l) => explode(' · ', $l)[0], $picker->unitOptions($this->dhk->id, $this->knee->id))))->toBe(['SN-3']);
});
