<?php

use App\Enums\Governorate;
use App\Enums\OverviewMetric;
use App\Enums\PaymentTerms;
use App\Enums\Role;
use App\Enums\Specialty;
use App\Filament\Admin\Pages\OverviewPage;
use App\Filament\Admin\Widgets\OverviewLauncher;
use App\Models\Category;
use App\Models\Product;
use App\Services\Insight\OverviewService;
use App\Services\Insight\SystemSearch;
use App\Services\Money\FxService;
use App\Services\Sales\SalesService;
use App\Services\Settings\SettingsRepository;
use Database\Seeders\InventoryBaseSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
 * The Main page buttons: stations, stock, value, sales and profit per region (Iraq, Kurdistan,
 * both), governorate and specialty; the specialty buttons; the search over the whole system.
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    app(SettingsRepository::class)->set('security.require_admin_mfa', false);
    $this->dhk = branch('DHK');
    $this->dhk->update(['governorate' => Governorate::Duhok]);
    $this->bgw = branch('BGW');
    $this->bgw->update(['governorate' => Governorate::Baghdad]);
    $this->ebl = branch('EBL'); // no governorate yet
    $this->admin = userWithRole(Role::SuperAdmin, [$this->dhk]);
    app(FxService::class)->setRate('USD', 'IQD', '1460', $this->admin);
    app(FxService::class)->setRate('EUR', 'USD', '1.10', $this->admin);

    $implants = Category::query()->create(['code' => 'IMPL', 'name' => ['en' => 'Implants']]);
    $knee = Category::query()->create(['code' => 'KNEE', 'name' => ['en' => 'Knee'], 'parent_id' => $implants->id, 'specialty' => Specialty::Arthroplasty]);
    $trauma = Category::query()->create(['code' => 'TRAUMA', 'name' => ['en' => 'Trauma'], 'specialty' => Specialty::Trauma]);
    $plates = Category::query()->create(['code' => 'PLATE', 'name' => ['en' => 'Plates', 'ar' => 'صفائح'], 'parent_id' => $trauma->id]);

    $this->kneeTray = Product::factory()->create(['sku' => 'KN-TRAY', 'name' => ['en' => 'Tibial tray', 'ar' => 'صينية قصبية'], 'category_id' => $knee->id, 'selling_price' => '900']);
    $this->plate = Product::factory()->create(['sku' => 'TR-PLATE', 'category_id' => $plates->id]);
    $this->other = Product::factory()->create(['sku' => 'NO-SPEC', 'category_id' => $implants->id]);

    stockIn($this->dhk, $this->admin, [
        ['product_id' => $this->kneeTray->id, 'serial' => 'K-1'],
        ['product_id' => $this->kneeTray->id, 'serial' => 'K-2'],
        ['product_id' => $this->plate->id, 'serial' => 'P-1', 'unit_cost' => '146000', 'cost_currency' => 'IQD'],
        ['product_id' => $this->other->id, 'serial' => 'O-1'],
    ]);
    stockIn($this->bgw, $this->admin, [['product_id' => $this->plate->id, 'serial' => 'P-2']]);
    stockIn($this->ebl, $this->admin, [['product_id' => $this->plate->id, 'serial' => 'P-3']]);

    $this->service = app(OverviewService::class);
    $this->month = [now(config('orthotrack.timezone'))->startOfMonth(), now(config('orthotrack.timezone'))->endOfDay()];
});

function overviewTotal($test, OverviewMetric $metric, ?string $region = null, ?Governorate $g = null, ?Specialty $s = null, $user = null): string
{
    $facts = $test->service->facts($user ?? $test->admin, $metric, ...$test->month);

    return $test->service->total($metric, $test->service->filter($facts, $region, $g, $s));
}

it('knows the Kurdistan Region and guesses governorates and specialties from names', function () {
    expect(Governorate::inRegion(Governorate::KURDISTAN))->toBe([Governorate::Duhok, Governorate::Erbil, Governorate::Sulaymaniyah, Governorate::Halabja])
        ->and(Governorate::inRegion(Governorate::IRAQ))->toHaveCount(15)
        ->and(Governorate::guess('Hawler'))->toBe(Governorate::Erbil)
        ->and(Governorate::guess('دهۆک'))->toBe(Governorate::Duhok)
        ->and(Governorate::guess('Mosul'))->toBe(Governorate::Nineveh)
        ->and(Governorate::guess('Head office'))->toBeNull()
        ->and(Specialty::guess('HIP Hip'))->toBe(Specialty::Arthroplasty)
        ->and(Specialty::guess('Plates'))->toBe(Specialty::Trauma)
        ->and(Specialty::guess('Pedicle screws'))->toBe(Specialty::Spine)
        ->and(Specialty::guess('Bone cement'))->toBeNull();
});

it('counts stock per region, governorate and specialty (a product takes its nearest category\'s specialty)', function () {
    expect(overviewTotal($this, OverviewMetric::Stock, Governorate::KURDISTAN))->toBe('4')
        ->and(overviewTotal($this, OverviewMetric::Stock, Governorate::IRAQ))->toBe('1')
        // Erbil's station has no governorate yet: not in the totals.
        ->and(overviewTotal($this, OverviewMetric::Stock))->toBe('5')
        ->and(overviewTotal($this, OverviewMetric::Stock, null, Governorate::Duhok, Specialty::Arthroplasty))->toBe('2')
        ->and(overviewTotal($this, OverviewMetric::Stock, null, Governorate::Duhok, Specialty::Trauma))->toBe('1')
        ->and(overviewTotal($this, OverviewMetric::Stock, null, null, Specialty::Trauma))->toBe('2')
        ->and(overviewTotal($this, OverviewMetric::Stock, null, Governorate::Erbil))->toBe('0')
        ->and(overviewTotal($this, OverviewMetric::Stations, null, null, Specialty::Trauma))->toBe('2');
});

it('values the stock in USD at the rate of its purchase', function () {
    // 100 + 100 (knee) + 146,000 IQD = 100 USD (plate) + 100 (other) in Duhok.
    expect(overviewTotal($this, OverviewMetric::Value, null, Governorate::Duhok))->toBe('400.00')
        ->and(overviewTotal($this, OverviewMetric::Value, null, Governorate::Duhok, Specialty::Trauma))->toBe('100.00')
        ->and(overviewTotal($this, OverviewMetric::Value, Governorate::IRAQ))->toBe('100.00');
});

it('counts sales and profit in the period, like the sales report', function () {
    app(SalesService::class)->sell($this->dhk, [
        'currency' => 'USD', 'payment_terms' => PaymentTerms::Later->value, 'walk_in_name' => 'Dr. Hawre', 'items' => [['raw' => 'K-1']],
    ], $this->admin);

    expect(overviewTotal($this, OverviewMetric::Sales, Governorate::KURDISTAN))->toBe('900.00')
        ->and(overviewTotal($this, OverviewMetric::Profit, null, Governorate::Duhok, Specialty::Arthroplasty))->toBe('800.00')
        ->and(overviewTotal($this, OverviewMetric::Sales, Governorate::IRAQ))->toBe('0.00')
        ->and(overviewTotal($this, OverviewMetric::Stock, null, Governorate::Duhok, Specialty::Arthroplasty))->toBe('1');

    // Last year: nothing.
    $lastYear = [Carbon::now()->subYear()->startOfYear(), Carbon::now()->subYear()->endOfYear()];
    expect($this->service->total(OverviewMetric::Sales, $this->service->facts($this->admin, OverviewMetric::Sales, ...$lastYear)))->toBe('0.00');
});

it('shows each person only the buttons and stations they may see', function () {
    $staff = userWithRole(Role::BranchStaff, [$this->bgw]);
    $metrics = $this->service->metrics($staff);

    expect($metrics)->toContain(OverviewMetric::Stock)
        ->and($metrics)->not->toContain(OverviewMetric::Profit)
        ->and(overviewTotal($this, OverviewMetric::Stock, null, null, null, $staff))->toBe('1')
        ->and($this->service->facts($staff, OverviewMetric::Profit, ...$this->month))->toBeEmpty();

    $this->actingAs($staff);
    expect(OverviewPage::canAccess())->toBeTrue();
});

it('walks from the totals to a governorate, a specialty and the products', function () {
    $this->actingAs($this->admin);

    Livewire::test(OverviewPage::class, ['metric' => 'stock'])
        ->assertSee('Total in Kurdistan')
        ->assertSee('Total in Iraq and Kurdistan')
        ->assertSee('Al-Qadisiyyah')
        ->assertSeeHtml('data-city="duhok" data-has="yes"')
        ->assertSeeHtml('data-city="basra" data-has="no"')
        ->assertSee('EBL')
        ->call('openCity', 'duhok')
        ->assertSee('Arthroplasty')
        ->assertSee('Sport medicine')
        ->call('openSpecialty', 'arthroplasty')
        ->assertSee('KN-TRAY')
        ->assertDontSee('TR-PLATE')
        ->call('back')
        ->call('openSpecialty', 'trauma')
        ->assertSee('TR-PLATE');

    Livewire::test(OverviewPage::class, ['focus' => 'trauma', 'period' => 'all'])
        ->assertSee('Trauma')
        ->assertSee('Stations')
        ->assertSee('Profit')
        ->call('openCity', 'baghdad')
        ->assertSee('TR-PLATE')
        ->assertSee('BGW');
});

it('opens the Main page with the buttons and the overview in every language', function (string $locale) {
    $this->admin->forceFill(['locale' => $locale])->save();
    $this->actingAs($this->admin->fresh());

    $this->get('/admin')->assertOk()->assertSee('data-overview-launcher', false);

    foreach (['metric=stations', 'metric=stock', 'metric=value', 'metric=sales&period=year', 'metric=profit&period=all',
        'metric=stock&city=duhok', 'metric=value&city=duhok&specialty=trauma', 'focus=arthroplasty', 'focus=sport_medicine&city=erbil',
        'metric=sales&period=custom&from=2026-01-01&until=2026-02-01'] as $query) {
        $this->get('/admin/overview?'.$query)->assertOk();
    }
})->with(['en', 'ar', 'ckb']);

it('searches the whole system: codes, lists and names in every language', function () {
    $this->actingAs($this->admin);
    $search = app(SystemSearch::class);

    $groups = fn (string $text) => collect($search->search($this->admin, $text))->mapWithKeys(fn ($g) => [$g['group'] => collect($g['items'])->pluck('title')->all()]);

    expect($groups('K-1')->flatten()->implode(' '))->toContain('K-1')
        ->and($groups('KN-TRAY')->flatten()->implode(' '))->toContain('KN-TRAY')
        // Names kept in Arabic are found too.
        ->and($groups('صينية')->get('Products'))->toBe(['Tibial tray'])
        ->and($search->search($this->admin, 'x'))->toBe([]);

    Livewire::test(OverviewLauncher::class)
        ->assertSee('Arthroplasty')
        ->assertSee('Stations')
        ->set('search', 'Tibial')
        ->assertSee('Tibial tray')
        ->set('search', 'zzzz-nothing')
        ->assertSee('Nothing found');
});
