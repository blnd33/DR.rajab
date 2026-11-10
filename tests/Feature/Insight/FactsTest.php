<?php

use App\Enums\CustomerType;
use App\Enums\IssueSeverity;
use App\Enums\IssueType;
use App\Enums\Role;
use App\Models\Customer;
use App\Models\KitType;
use App\Models\Product;
use App\Services\Assets\KitService;
use App\Services\Insight\Fact;
use App\Services\Insight\FactContext;
use App\Services\Insight\FactRegistry;
use App\Services\Insight\FactResult;
use App\Services\Insight\Facts\Sales;
use App\Services\Issues\IssueService;
use App\Services\Money\CashBoxService;
use App\Services\Money\FxService;
use App\Services\Permissions\PermissionRegistry;
use App\Services\Reports\ReportContext;
use App\Services\Reports\SalesProfitReport;
use App\Services\Sales\SalesService;
use App\Services\Settings\SettingsRepository;
use Database\Seeders\InventoryBaseSeeder;
use Illuminate\Support\Facades\App;

/*
 * Facts (Phase 6): the shared answers behind the morning summary and Quick answers — each one
 * checks its own permission and branches and reuses the existing numbers.
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    $this->dhk = branch('DHK');
    $this->ebl = branch('EBL');
    $this->admin = userWithRole(Role::SuperAdmin, [$this->dhk]);
    app(FxService::class)->setRate('USD', 'IQD', '1500', $this->admin);
    app(FxService::class)->setRate('EUR', 'USD', '1.10', $this->admin);
    $this->facts = app(FactRegistry::class);
    $this->hospital = Customer::query()->create(['code' => 'HSP-1', 'type' => CustomerType::Hospital, 'name' => ['en' => 'Azadi'], 'credit_enabled' => true]);
});

/**
 * Sell one piece of a new product in DHK, paid cash or on credit to the hospital.
 */
function sellOne(string $sku, string $price, string $currency, bool $credit = false): void
{
    $product = Product::factory()->lot()->create(['sku' => $sku]);
    stockIn(test()->dhk, test()->admin, [['product_id' => $product->id, 'lot' => "L-{$sku}", 'quantity' => 1, 'unit_cost' => '10']]);
    $box = app(CashBoxService::class)->create(test()->dhk, ['name' => ['en' => "Cash {$currency} {$sku}"], 'currency' => $currency, 'opening_balance' => '0'], test()->admin);

    app(SalesService::class)->sell(test()->dhk, [
        'currency' => $currency,
        'customer_id' => test()->hospital->id,
        'payment_terms' => $credit ? 'credit' : 'cash',
        'cash_box_id' => $credit ? null : $box->id,
        'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => $price]],
    ], test()->admin);
}

it('answers every fact in every language, empty when nothing happened', function (string $locale) {
    App::setLocale($locale);
    $context = FactContext::yesterday($this->admin);

    $facts = $this->facts->visibleTo($this->admin);
    expect($facts)->toHaveCount(count(FactRegistry::FACTS));

    foreach ($facts as $fact) {
        $result = $this->facts->build($fact, $context);
        expect($result)->toBeInstanceOf(FactResult::class)
            ->and($result->key)->toBe($fact::key())
            ->and($result->title)->not->toBe('')
            ->and(FactResult::fromArray($result->toArray()))->toEqual($result);
    }
})->with(['en', 'ar', 'ckb']);

it('has unique keys and the two new permissions in the role templates', function () {
    expect(collect(FactRegistry::FACTS)->map(fn ($class) => $class::key())->duplicates())->toBeEmpty()
        ->and(PermissionRegistry::exists('summary.receive'))->toBeTrue()
        ->and(PermissionRegistry::exists('assistant.use'))->toBeTrue()
        ->and(PermissionRegistry::roleTemplates()[Role::Accountant->value])->toContain('summary.receive', 'assistant.use')
        ->and(PermissionRegistry::roleTemplates()[Role::BranchStaff->value])->toContain('assistant.use')->not->toContain('summary.receive');
});

it('shows each person only the facts their permissions allow', function () {
    $keys = fn (Role $role) => collect($this->facts->visibleTo(userWithRole($role, [$this->dhk])))->map(fn (Fact $f) => $f::key())->all();

    expect($keys(Role::Doctor))->toContain('surgeries-done', 'kits-out')->not->toContain('sales', 'profit', 'cash-in-boxes')
        ->and($keys(Role::Cashier))->toContain('payments-received', 'cash-in-boxes')->not->toContain('profit', 'kits-out')
        ->and($keys(Role::Accountant))->toContain('sales', 'profit', 'expenses', 'supplier-invoices-due')->not->toContain('approvals-waiting')
        ->and($keys(Role::BranchStaff))->toContain('transfers-to-receive', 'below-minimum')->not->toContain('sales', 'open-issues');

    // Without reports.profit: sales yes, profit no.
    $noProfit = userWithRole(Role::Accountant, [$this->dhk]);
    $noProfit->roles->first()->revokePermissionTo('reports.profit');
    $noProfit = $noProfit->fresh();
    expect($this->facts->find('profit')->canSee($noProfit))->toBeFalse()
        ->and($this->facts->build('profit', FactContext::make($noProfit)))->toBeNull()
        ->and($this->facts->find('sales')->canSee($noProfit))->toBeTrue();
});

it('keeps to the person\'s branches and the chosen branch', function () {
    $issues = app(IssueService::class);
    $issues->open(IssueType::CashDifference, IssueSeverity::Warning, $this->dhk->id, null, 'Duhok difference');
    $issues->open(IssueType::CashDifference, IssueSeverity::Warning, $this->ebl->id, null, 'Erbil difference');

    expect($this->facts->build('open-issues', FactContext::make($this->admin))->number('issues'))->toBe(2)
        ->and($this->facts->build('open-issues', FactContext::make($this->admin, ['branch' => $this->ebl->id]))->lines[0]['label'])->toBe('Erbil difference');

    $duhok = userWithRole(Role::Accountant, [$this->dhk]);
    $result = $this->facts->build('open-issues', FactContext::make($duhok));
    expect($result->number('issues'))->toBe(1)
        ->and($result->lines[0]['label'])->toBe('Duhok difference')
        // Asking for a branch outside their scope gives nothing, not the other branch.
        ->and($this->facts->build('open-issues', FactContext::make($duhok, ['branch' => $this->ebl->id]))->number('issues'))->toBe(0);
});

it('adds up sales in the display currency with each sale\'s own exchange rate', function () {
    sellOne('P-USD', '100', 'USD');
    sellOne('P-IQD', '150000', 'IQD');

    // A later rate does not change sales already made.
    app(FxService::class)->setRate('USD', 'IQD', '1000', $this->admin);

    $sales = $this->facts->build('sales', FactContext::make($this->admin));
    expect($sales->number('total'))->toBe('200.00')
        ->and($sales->number('invoices'))->toBe(2)
        ->and($sales->lines)->toBe([['label' => 'Azadi', 'value' => 'USD 200.00']]);

    $inIqd = $this->facts->build('sales', FactContext::make($this->admin, ['currency' => 'IQD']));
    expect($inIqd->number('total'))->toBe('300000.00');

    $profit = $this->facts->build('profit', FactContext::make($this->admin));
    expect($profit->number('profit'))->toBe('180.00');

    $paid = $this->facts->build('payments-received', FactContext::make($this->admin));
    expect($paid->number('total'))->toBe('200.00')->and($paid->number('payments'))->toBe(2)
        ->and($paid->lines)->toBe([['label' => 'Paid with the sale', 'value' => 'USD 200.00']]);
});

it('lists what hospitals still owe', function () {
    app(SettingsRepository::class)->set('features.credit_sales', true);
    sellOne('P-CREDIT', '300000', 'IQD', credit: true);

    $balances = $this->facts->build('hospital-balances', FactContext::make($this->admin));

    expect($balances->number('total'))->toBe('200.00')
        ->and($balances->number('customers'))->toBe(1)
        ->and($balances->lines[0])->toBe(['label' => 'Azadi', 'value' => 'USD 200.00']);
});

it('counts a sale on the business day it was made, also after midnight', function () {
    // 01:30 in Baghdad on 11 November is 22:30 UTC on 10 November.
    $this->travelTo(now()->setDate(2026, 11, 10)->setTime(22, 30), function () {
        sellOne('P-NIGHT', '50', 'USD');
    });

    $onThe11th = FactContext::day($this->admin, '2026-11-11');
    $onThe10th = FactContext::day($this->admin, '2026-11-10');

    expect(app(Sales::class)->build($onThe11th)->number('total'))->toBe('50.00')
        ->and(app(Sales::class)->build($onThe10th)->number('total'))->toBe('0.00');

    // The report itself agrees (the period is compared in UTC).
    $report = app(SalesProfitReport::class);
    $rows = $report->rows(ReportContext::make($report, $this->admin, ['from' => '2026-11-10', 'until' => '2026-11-10']));
    expect($rows)->toBe([]);
});

it('shows kits out at hospitals, overdue first with the days late', function () {
    $kits = app(KitService::class);
    $type = KitType::query()->create(['code' => 'KNEE', 'name' => ['en' => 'Knee kit']]);
    $late = $kits->create($this->dhk, ['kit_type_id' => $type->id], $this->admin);
    $onTime = $kits->create($this->dhk, ['kit_type_id' => $type->id], $this->admin);

    $kits->checkout($late, $this->hospital, $this->admin)->forceFill(['due_back_at' => now()->subDays(3)])->save();
    $kits->checkout($onTime, $this->hospital, $this->admin)->forceFill(['due_back_at' => now()->addDays(2)])->save();

    $result = $this->facts->build('kits-out', FactContext::make($this->admin));

    expect($result->number('out'))->toBe(2)
        ->and($result->number('overdue'))->toBe(1)
        ->and($result->tone)->toBe(FactResult::DANGER)
        ->and($result->lines[0])->toBe(['label' => $late->code.' · Azadi', 'value' => '3 days late'])
        ->and($result->lines[1]['value'])->toBe('Out');

    $moved = $this->facts->build('kits-moved', FactContext::make($this->admin));
    expect($moved->numbers)->toBe(['sent' => 2, 'returned' => 0]);
});
