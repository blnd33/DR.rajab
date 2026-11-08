<?php

use App\Enums\Role;
use App\Exceptions\Domain\MoneyRuleViolation;
use App\Filament\Admin\Resources\SalesInvoices\Pages\CreateSalesInvoice;
use App\Livewire\Ops\Cash\SaleCreate;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Services\Money\CashBoxService;
use App\Services\Money\FxService;
use App\Services\Reports\ReportContext;
use App\Services\Reports\SalesProfitReport;
use App\Services\Sales\SalesService;
use App\Support\CurrentBranch;
use Database\Seeders\InventoryBaseSeeder;
use Filament\Forms\Components\Repeater;
use Livewire\Livewire;

/*
 * Sales split by percentage (client, 2026-11-08): three pieces cost us 500 $; each piece's
 * share of that cost decides its share of the one selling price; the report shows how much
 * each piece brought in and its percentage.
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    $this->dhk = branch('DHK');
    $this->admin = userWithRole(Role::SuperAdmin, [$this->dhk]);
    $this->cashier = userWithRole(Role::Cashier, [$this->dhk]);
    app(FxService::class)->setRate('USD', 'IQD', '1460', $this->admin);
    app(FxService::class)->setRate('EUR', 'USD', '1.10', $this->admin);

    $this->usdBox = app(CashBoxService::class)->create($this->dhk, ['name' => ['en' => 'Cash USD'], 'currency' => 'USD', 'opening_balance' => '0'], $this->admin);
    $this->iqdBox = app(CashBoxService::class)->create($this->dhk, ['name' => ['en' => 'Cash IQD'], 'currency' => 'IQD', 'opening_balance' => '0'], $this->admin);
    $this->stem = Product::factory()->create(['sku' => 'STEM']);
    $this->cup = Product::factory()->create(['sku' => 'CUP']);
    $this->head = Product::factory()->lot()->create(['sku' => 'HEAD']);
    stockIn($this->dhk, $this->admin, [
        ['product_id' => $this->stem->id, 'serial' => 'S-1', 'unit_cost' => '250'],
        ['product_id' => $this->cup->id, 'serial' => 'C-1', 'unit_cost' => '150'],
        ['product_id' => $this->head->id, 'lot' => 'H1', 'quantity' => 4, 'unit_cost' => '50'],
    ]);
    $this->pieces = [['raw' => 'S-1'], ['raw' => 'C-1'], ['raw' => 'HEAD', 'quantity' => 2]];
});

function splitSale(array $extra = []): SalesInvoice
{
    $test = test();

    return app(SalesService::class)->sell($test->dhk, $extra + [
        'currency' => 'USD',
        'cash_box_id' => $test->usdBox->id,
        'pricing' => SalesService::SPLIT,
        'sale_total' => '800',
        'items' => $test->pieces,
    ], $test->cashier);
}

it('splits one price by each piece\'s share of the cost (500 $ cost, sold for 800 $)', function () {
    $invoice = splitSale();
    $lines = $invoice->items()->orderBy('id')->get();

    expect($invoice->pricing)->toBe('split')
        ->and((string) $invoice->total)->toBe('800.0000')
        ->and((string) $this->usdBox->fresh()->current_balance)->toBe('800.0000')
        ->and($lines->map(fn ($l) => [(string) $l->cost_share, (string) $l->line_total, (string) $l->price_share])->all())->toBe([
            ['50.0000', '400.0000', '50.0000'],   // stem 250 of 500
            ['30.0000', '240.0000', '30.0000'],   // cup 150 of 500
            ['20.0000', '160.0000', '20.0000'],   // two heads 100 of 500
        ])
        ->and((string) $lines[2]->unit_price)->toBe('80.0000');
});

it('lets the seller change the percentages, which must add up to 100', function () {
    $this->pieces[0]['share'] = '60';
    $this->pieces[1]['share'] = '25';

    expect(fn () => splitSale())->toThrow(MoneyRuleViolation::class, 'every item');

    $this->pieces[2]['share'] = '10';
    expect(fn () => splitSale())->toThrow(MoneyRuleViolation::class, '100 %');

    $this->pieces[2]['share'] = '15';
    $invoice = splitSale();

    expect($invoice->items()->orderBy('id')->pluck('line_total')->map(fn ($v) => (string) $v)->all())->toBe(['480.0000', '200.0000', '120.0000'])
        ->and($invoice->items()->orderBy('id')->pluck('cost_share')->map(fn ($v) => (string) $v)->all())->toBe(['50.0000', '30.0000', '20.0000']);
});

it('keeps the lines adding up to the total exactly when the split does not round', function () {
    $invoice = splitSale(['sale_total' => '100.01']);
    $sum = $invoice->items->reduce(fn ($s, $l) => bcadd($s, (string) $l->line_total, 4), '0');

    expect($sum)->toBe('100.0100')->and((string) $invoice->total)->toBe('100.0100');
});

it('needs a total and refuses an extra discount when split', function () {
    expect(fn () => splitSale(['sale_total' => '']))->toThrow(MoneyRuleViolation::class, 'total price')
        ->and(fn () => splitSale(['discount' => '10']))->toThrow(MoneyRuleViolation::class, 'discount');
});

it('sells split in Iraqi dinars with the cost converted to dinars', function () {
    $invoice = splitSale(['currency' => 'IQD', 'cash_box_id' => $this->iqdBox->id, 'sale_total' => '1168000']);

    expect($invoice->items()->orderBy('id')->pluck('line_total')->map(fn ($v) => (string) $v)->all())->toBe(['584000.0000', '350400.0000', '233600.0000'])
        ->and($invoice->items()->orderBy('id')->pluck('cost_share')->map(fn ($v) => (string) $v)->all())->toBe(['50.0000', '30.0000', '20.0000']);
});

it('stores the percentages for per-item sales too', function () {
    $invoice = app(SalesService::class)->sell($this->dhk, [
        'currency' => 'USD', 'cash_box_id' => $this->usdBox->id,
        'items' => [['raw' => 'S-1', 'unit_price' => '300'], ['raw' => 'C-1', 'unit_price' => '100']],
    ], $this->cashier);

    expect($invoice->pricing)->toBe('per_item')
        ->and($invoice->items()->orderBy('id')->pluck('cost_share')->map(fn ($v) => (string) $v)->all())->toBe(['62.5000', '37.5000'])
        ->and($invoice->items()->orderBy('id')->pluck('price_share')->map(fn ($v) => (string) $v)->all())->toBe(['75.0000', '25.0000']);
});

it('shows each piece with its cost %, received and received % in the report', function () {
    splitSale();
    $report = app(SalesProfitReport::class);
    $rows = collect($report->rows(ReportContext::make($report, $this->admin, ['group' => 'line'])))->keyBy('sku');

    expect($rows['STEM'])->toMatchArray(['revenue' => '400.00', 'cost' => '250.00', 'profit' => '150.00'])
        ->and((string) $rows['STEM']['cost_share'])->toBe('50.0')
        ->and((string) $rows['STEM']['price_share'])->toBe('50.0')
        ->and((string) $rows['HEAD']['price_share'])->toBe('20.0');

    $byProduct = collect($report->rows(ReportContext::make($report, $this->admin, ['group' => 'product'])))->keyBy('sku');
    expect((string) $byProduct['CUP']['revenue_pct'])->toBe('30.0');
});

it('previews the split on the phone and sells it', function () {
    CurrentBranch::set($this->dhk->id);

    $screen = Livewire::actingAs($this->cashier)->test(SaleCreate::class)
        ->set('currency', 'USD')
        ->set('pricing', 'split')
        ->call('handleScan', 'S-1')
        ->call('handleScan', 'C-1')
        ->set('saleTotal', '800')
        ->assertSee('Cost share');

    expect(collect($screen->instance()->splitPreview())->pluck('amount')->all())->toBe(['500.00', '300.00']); // 250 and 150 of 400 → 62.5 % and 37.5 %

    $screen->call('submit');

    expect(SalesInvoice::query()->sole())->pricing->toBe('split')->total->toEqual('800.0000');
});

it('previews the split in the admin form and sells it', function () {
    $undo = Repeater::fake();

    Livewire::actingAs($this->admin)->test(CreateSalesInvoice::class)
        ->fillForm([
            'branch_id' => $this->dhk->id,
            'currency' => 'USD',
            'cash_box_id' => $this->usdBox->id,
            'pricing' => SalesService::SPLIT,
            'sale_total' => '800',
            'items' => [['raw' => 'S-1', 'quantity' => 1], ['raw' => 'C-1', 'quantity' => 1]],
        ])
        ->assertSee('How the price is split')
        ->assertSee('62.50 %')
        ->assertSee('500.00')
        ->call('create')
        ->assertHasNoFormErrors();

    $undo();

    expect(SalesInvoice::query()->sole())->pricing->toBe('split')->total->toEqual('800.0000')
        ->and(SalesInvoice::query()->sole()->items()->orderBy('id')->pluck('line_total')->map(fn ($v) => (string) $v)->all())->toBe(['500.0000', '300.0000']);
});
