<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\Graphs;
use App\Filament\Admin\Pages\ViewGraph;
use App\Models\Product;
use App\Services\Graphs\Graph;
use App\Services\Graphs\GraphContext;
use App\Services\Graphs\GraphData;
use App\Services\Graphs\GraphRegistry;
use App\Services\Money\CashBoxService;
use App\Services\Money\FxService;
use App\Services\Sales\SalesService;
use Database\Seeders\InventoryBaseSeeder;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;

/*
 * Graphs page (client, 2026-11-08): every part of the system as a chart, searchable, in the
 * admin panel. Graphs use the reports' numbers and the reports' access rules.
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    $this->dhk = branch('DHK');
    $this->admin = userWithRole(Role::SuperAdmin, [$this->dhk]);
    app(FxService::class)->setRate('USD', 'IQD', '1460', $this->admin);
    app(FxService::class)->setRate('EUR', 'USD', '1.10', $this->admin);
    $this->registry = app(GraphRegistry::class);
});

it('builds every graph in every language', function (string $locale) {
    App::setLocale($locale);
    $context = GraphContext::make($this->admin);

    foreach ($this->registry->visibleTo($this->admin) as $graph) {
        expect($this->registry->build($graph, $context))->toBeInstanceOf(GraphData::class, $graph->key);
    }
})->with(['en', 'ar', 'ckb']);

it('has unique keys and at least one graph per section', function () {
    $graphs = $this->registry->all();

    expect(collect($graphs)->pluck('key')->duplicates())->toBeEmpty()
        ->and(collect($graphs)->pluck('section')->unique()->count())->toBe(7)
        ->and(count($graphs))->toBeGreaterThanOrEqual(30);
});

it('finds graphs by words in any language', function () {
    expect(collect($this->registry->search($this->admin, 'surgeries hospital'))->pluck('key')->all())->toBe(['surgeries-by-hospital'])
        ->and(collect($this->registry->search($this->admin, 'dinar'))->pluck('key')->all())->toBe(['sales-by-currency'])
        ->and($this->registry->search($this->admin, 'zzz-nothing'))->toBe([]);

    App::setLocale('ar');
    expect(collect($this->registry->search($this->admin, 'surgeries hospital'))->pluck('key')->all())->toBe(['surgeries-by-hospital']);
});

it('draws the sales graphs from the sales numbers, folding small slices into Other', function () {
    $box = app(CashBoxService::class)->create($this->dhk, ['name' => ['en' => 'Cash'], 'currency' => 'USD', 'opening_balance' => '0'], $this->admin);
    $lines = [];

    foreach (range(1, 9) as $i) {
        $product = Product::factory()->lot()->create(['sku' => "P{$i}"]);
        stockIn($this->dhk, $this->admin, [['product_id' => $product->id, 'lot' => "L{$i}", 'quantity' => 5, 'unit_cost' => '10']]);
        $lines[] = ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => (string) ($i * 10)];
    }

    app(SalesService::class)->sell($this->dhk, ['currency' => 'USD', 'cash_box_id' => $box->id, 'items' => $lines], $this->admin);

    $byItem = $this->registry->build($this->registry->find('sales-by-item'), GraphContext::make($this->admin));

    expect($byItem->labels)->toHaveCount(8)
        ->and($byItem->other)->toBe([false, false, false, false, false, false, false, true])
        ->and($byItem->values()[0])->toEqual(90.0)
        ->and($byItem->values()[7])->toEqual(30.0) // 20 + 10
        ->and($byItem->total())->toEqual(450.0)
        ->and($byItem->currency)->toBe('USD');

    $perMonth = $this->registry->build($this->registry->find('sales-per-month'), GraphContext::make($this->admin));
    expect($perMonth->labels)->toHaveCount(12)->and($perMonth->total())->toEqual(450.0)->and($perMonth->series)->toHaveCount(2);
});

it('hides cost and profit graphs from people without profit permission', function () {
    $manager = userWithRole(Role::Accountant, [$this->dhk]);
    $manager->revokePermissionTo('reports.profit');
    $manager->roles->each->revokePermissionTo('reports.profit');
    $manager = $manager->fresh();

    $keys = collect($this->registry->visibleTo($manager))->pluck('key');

    if ($keys->isEmpty()) {
        $this->markTestSkipped('Accountants have no reports in this setup.');
    }

    expect($keys)->not->toContain('profit-per-month')->not->toContain('implant-cost-per-month');

    $this->actingAs($manager);
    Livewire::test(ViewGraph::class, ['graph' => 'profit-per-month'])->assertForbidden();
});

it('opens the Graphs page and a graph with its table', function () {
    $this->actingAs($this->admin);

    Livewire::test(Graphs::class)->assertOk()->assertSee('Sales per month')
        ->set('search', 'expiry')->assertSee('Items by expiry level')->assertDontSee('Sales per month')
        ->call('chooseSection', 'transfers')->set('search', '')->assertSee('Transfers by route')->assertDontSee('Items by expiry level');

    Livewire::test(ViewGraph::class, ['graph' => 'catalog-by-usage'])->assertOk()->assertSee('Catalogue by usage');
    Livewire::test(ViewGraph::class, ['graph' => 'no-such-graph'])->assertNotFound();
});

it('refuses the Graphs page to people without reports', function () {
    $doctor = userWithRole(Role::Doctor, [$this->dhk]);

    if (GraphRegistry::canUse($doctor)) {
        $this->markTestSkipped('Doctors may see reports in this setup.');
    }

    // Sent back to sign-in (no admin panel) or refused: never shown.
    expect($this->actingAs($doctor)->get(Graphs::getUrl())->status())->toBeIn([302, 403]);
});

it('keeps graph kinds to the four drawn forms', function () {
    expect(collect($this->registry->all())->pluck('kind')->unique()->diff([Graph::DOUGHNUT, Graph::RANKING, Graph::MONTHS, Graph::LINE]))->toBeEmpty();
});

it('lets the user draw any graph as a pie or bars, and graphs over time as a line too', function () {
    $this->actingAs($this->admin);

    expect($this->registry->find('catalog-by-usage')->views())->toBe([Graph::VIEW_PIE, Graph::VIEW_BARS])
        ->and($this->registry->find('sales-per-month')->views())->toBe([Graph::VIEW_PIE, Graph::VIEW_BARS, Graph::VIEW_LINE]);

    $page = Livewire::test(ViewGraph::class, ['graph' => 'catalog-by-usage'])
        ->assertSee('Draw as')
        ->call('chooseView', 'bars')->assertSet('chartView', 'bars');
    expect($page->instance()->currentView())->toBe(Graph::VIEW_BARS);

    // A line is not offered between names; choosing the graph's own form clears the choice.
    $page->call('chooseView', 'line')->assertSet('chartView', 'bars')
        ->call('chooseView', 'pie')->assertSet('chartView', '');

    // Opened with ?view=bars from a shared link.
    $shared = Livewire::withQueryParams(['graph' => 'sales-per-month', 'view' => 'bars'])->test(ViewGraph::class);
    expect($shared->instance()->currentView())->toBe(Graph::VIEW_BARS);
});

it('draws a pie only when the numbers make a whole, folding past seven slices', function () {
    $graph = $this->registry->find('profit-per-month');
    $months = new GraphData(array_map(fn ($m) => "2026-{$m}", range(1, 12)), [['label' => 'Profit', 'values' => array_map(fn ($m) => (float) $m * 10, range(1, 12))]], 'USD');

    [$pie, $reason] = $this->registry->asPie($graph, $months);
    expect($reason)->toBeNull()
        ->and($pie->labels)->toHaveCount(8)
        ->and($pie->labels[0])->toBe('2026-12')
        ->and($pie->other[7])->toBeTrue()
        ->and($pie->total())->toEqual($months->total());

    $loss = new GraphData(['2026-01', '2026-02'], [['label' => 'Profit', 'values' => [50.0, -20.0]]], 'USD');
    expect($this->registry->asPie($graph, $loss))->toBe([null, 'A pie cannot show negative numbers.']);

    [$none, $why] = $this->registry->asPie($this->registry->find('stock-value-history'), $months);
    expect($none)->toBeNull()->and($why)->toContain('do not add up');

    // A shared ?view=pie link to a graph that cannot be a pie still opens (in its own form).
    $this->actingAs($this->admin);
    Livewire::withQueryParams(['graph' => 'stock-value-history', 'view' => 'pie'])->test(ViewGraph::class)
        ->assertOk();
});
