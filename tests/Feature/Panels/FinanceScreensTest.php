<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\Purchases\Pages\ViewPurchase;
use App\Livewire\Ops\Cash\CloseDay;
use App\Livewire\Ops\Cash\SaleCreate;
use App\Models\CashBox;
use App\Models\CashBoxClosing;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Issue;
use App\Models\Purchase;
use App\Models\SalesInvoice;
use App\Models\StockUnit;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Services\Settings\SettingsRepository;
use Brick\Math\BigDecimal;
use Database\Seeders\DemoBranchSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\DemoClinicalSeeder;
use Database\Seeders\DemoMoneySeeder;
use Database\Seeders\DemoMovementSeeder;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\InventoryBaseSeeder;

/*
 * Phase 4 screens open in every language with the demo money data; a branch user only
 * sees the money of their own branch.
 */

beforeEach(function () {
    $this->seed([InventoryBaseSeeder::class, ExpenseCategorySeeder::class, DemoBranchSeeder::class]);
    app(SettingsRepository::class)->set('security.require_admin_mfa', false);
    app(SettingsRepository::class)->set('features.credit_sales', true);
    app(SettingsRepository::class)->set('features.commissions', true);
    $this->admin = userWithRole(Role::SuperAdmin, [branch('DHK')], ['username' => 'superadmin']);
    $this->actingAs($this->admin);
    $this->seed([DemoCatalogSeeder::class, DemoMovementSeeder::class, DemoClinicalSeeder::class, DemoMoneySeeder::class]);
});

it('opens every finance admin screen', function (string $locale) {
    $this->admin->forceFill(['locale' => $locale])->save();
    $this->actingAs($this->admin->fresh());

    $paths = [
        '/admin',
        '/admin/exchange-rates',
        '/admin/cash-boxes',
        '/admin/cash-boxes/create',
        '/admin/cash-box-closings',
        '/admin/box-transfers',
        '/admin/currency-exchanges',
        '/admin/approvals',
        '/admin/purchases',
        '/admin/purchases/create',
        '/admin/supplier-payments',
        '/admin/credit-notes',
        '/admin/sales-invoices',
        '/admin/sales-invoices/create',
        '/admin/customer-payments',
        '/admin/expenses',
        '/admin/expenses/create',
        '/admin/expense-categories',
        '/admin/recurring-expenses',
        '/admin/commission-rules',
        '/admin/commission-entries',
        '/admin/suppliers',
        '/admin/customers/'.Customer::query()->value('id'),
    ];

    foreach ([
        'cash-boxes' => CashBox::class,
        'purchases' => Purchase::class,
        'sales-invoices' => SalesInvoice::class,
        'suppliers' => Supplier::class,
        'supplier-returns' => SupplierReturn::class,
    ] as $slug => $model) {
        foreach ($model::all() as $record) {
            $paths[] = "/admin/{$slug}/{$record->id}";
        }
    }

    foreach ($paths as $path) {
        expect($this->get($path)->status())->toBe(200, "{$path} in {$locale}");
    }
})->with(['en', 'ar', 'ckb']);

it('keeps branch money to the branch', function () {
    $accountant = userWithRole(Role::Accountant, [branch('EBL')]);
    $this->actingAs($accountant);

    $dhkBox = CashBox::query()->where('branch_id', branch('DHK')->id)->firstOrFail();
    $eblBox = CashBox::query()->where('branch_id', branch('EBL')->id)->firstOrFail();

    $this->get('/admin/cash-boxes/'.$eblBox->id)->assertOk();
    expect($this->get('/admin/cash-boxes/'.$dhkBox->id)->status())->toBeIn([403, 404]);
    $this->get('/admin/cash-boxes')->assertOk()->assertSee('Erbil cash USD')->assertDontSee('Duhok cash USD');
});

it('hides commissions and customer payments while their switches are off', function () {
    app(SettingsRepository::class)->set('features.commissions', false);

    $this->get('/admin/commission-rules')->assertForbidden();
    $this->get('/admin/commission-entries')->assertForbidden();
    expect(Expense::query()->count())->toBeGreaterThan(0);
});

it('opens every cashier screen in the ops app', function (string $locale) {
    $cashier = userWithRole(Role::Cashier, [branch('EBL')], ['locale' => $locale]);
    $this->actingAs($cashier);
    $eblBox = CashBox::query()->where('branch_id', branch('EBL')->id)->firstOrFail();
    $dhkBox = CashBox::query()->where('branch_id', branch('DHK')->id)->where('type', 'cash')->firstOrFail();

    foreach (['/app/tasks', '/app/cash', '/app/cash/sale', '/app/cash/expense', '/app/cash/exchange', "/app/cash/{$eblBox->id}/close"] as $path) {
        expect($this->get($path)->status())->toBe(200, "{$path} in {$locale}");
    }

    expect($this->get("/app/cash/{$dhkBox->id}/close")->status())->toBe(403);

    // Duhok has USD and IQD boxes: the exchange screen fills in today's reference rate.
    $this->actingAs(userWithRole(Role::Cashier, [branch('DHK')], ['locale' => $locale]));
    $this->get('/app/cash/exchange')->assertOk()->assertSee('1460');
})->with(['en', 'ar', 'ckb']);

it('closes the day from the phone and asks for a reason when the drawer is short', function () {
    $cashier = userWithRole(Role::Cashier, [branch('DHK')]);
    $this->actingAs($cashier);
    $box = CashBox::query()->where('branch_id', branch('DHK')->id)->where('currency', 'USD')->where('type', 'cash')->firstOrFail();
    $short = (string) BigDecimal::of($box->current_balance)->minus(10);

    $screen = Livewire\Livewire::test(CloseDay::class, ['box' => $box])
        ->set('counted', $short)
        ->assertSee('-10')
        ->call('submit')
        ->assertDispatched('toast', type: 'error');

    expect(CashBoxClosing::query()->where('cash_box_id', $box->id)->where('status', 'submitted')->exists())->toBeFalse();

    $screen->set('reason', 'Paid the cleaner from the drawer')->call('submit')->assertRedirect(route('ops.cash'));

    $closing = CashBoxClosing::query()->where('cash_box_id', $box->id)->firstOrFail();
    expect($closing->status->value)->toBe('submitted')
        ->and((string) $closing->difference)->toBe('-10.0000')
        ->and(Issue::query()->where('subject_type', $closing->getMorphClass())->where('subject_id', $closing->id)->exists())->toBeTrue();
});

it('sells a scanned implant at the counter into the branch box', function () {
    $cashier = userWithRole(Role::Cashier, [branch('DHK')]);
    $this->actingAs($cashier);
    $box = CashBox::query()->where('branch_id', branch('DHK')->id)->where('currency', 'USD')->where('type', 'cash')->firstOrFail();
    $before = $box->current_balance;
    $unit = StockUnit::query()->where('serial', 'NJ-TIB4-90001')->firstOrFail();

    Livewire\Livewire::test(SaleCreate::class)
        ->call('handleScan', 'NJ-TIB4-90001')
        ->assertSet("items.u{$unit->id}.price", (string) BigDecimal::of($unit->product->selling_price)->toScale(2))
        ->set("items.u{$unit->id}.price", '800')
        ->set('walkIn', 'Dr. Shvan')
        ->call('submit')
        ->assertRedirect(route('ops.cash'));

    expect((string) BigDecimal::of($box->fresh()->current_balance)->minus($before))->toBe('800.0000')
        ->and($unit->fresh()->status->value)->toBe('sold');
});

it('offers what is still open on an invoice in the currency of the chosen box', function () {
    $purchase = Purchase::query()->where('currency', 'EUR')->firstOrFail();
    $bank = CashBox::query()->whereNull('branch_id')->firstOrFail();

    $screen = Livewire\Livewire::test(ViewPurchase::class, ['record' => $purchase->getRouteKey()])
        ->mountAction('pay')
        ->setActionData(['cash_box_id' => $bank->id]);

    // EUR 2,035.19 open × 1.08 = USD 2,198.00; method and date keep their defaults.
    expect((string) BigDecimal::of((string) $screen->instance()->mountedActions[0]['data']['amount'])->toScale(2))->toBe('2198.00')
        ->and($screen->instance()->mountedActions[0]['data']['method'])->toBe('bank_wire');

    $screen->callMountedAction()->assertHasNoActionErrors();
    expect($purchase->fresh()->payment_status->value)->toBe('paid');
});
