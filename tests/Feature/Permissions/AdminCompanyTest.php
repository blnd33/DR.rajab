<?php

use App\Enums\Company;
use App\Enums\OverviewMetric;
use App\Enums\Role;
use App\Models\Product;
use App\Services\Insight\OverviewService;
use App\Services\Permissions\BranchScopeResolver;
use App\Services\Stock\StockQuery;
use App\Support\AdminBranch;
use App\Support\AdminCompany;
use Database\Seeders\InventoryBaseSeeder;

/*
 * The admin top-bar company switch (KurdishMedicus | Al-Fadhaa' Bureau). All stations belong to
 * KurdishMedicus; Al-Fadhaa' Bureau has none yet, so its Main page is the same page showing zero.
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    $this->dhk = branch('DHK');
    $this->ebl = branch('EBL');
    $this->admin = userWithRole(Role::SuperAdmin, [$this->dhk], ['all_branches' => true]);

    $product = Product::factory()->create(['sku' => 'TR-1']);
    stockIn($this->dhk, $this->admin, [['product_id' => $product->id, 'serial' => 'S-1']]);
});

it('shows both companies in the top bar, the main one chosen', function () {
    expect(AdminCompany::current())->toBe(Company::KurdishMedicus);

    $this->actingAs($this->admin)->get('/admin')->assertOk()
        ->assertSee('data-company-switch', false)
        ->assertSee('KurdishMedicus')
        ->assertSee("Al-Fadhaa' Bureau")
        ->assertSee('data-branch-switch', false)
        ->assertDontSee('This company has no stations yet, so everything shows zero.');
});

it('opens the Main page of Al-Fadhaa\' Bureau with nothing in it, and goes back', function () {
    AdminBranch::set($this->dhk->id);

    $this->actingAs($this->admin)
        ->post(route('admin.company.switch'), ['company' => 'al_fadhaa'])
        ->assertRedirect('/admin');

    expect(session(AdminCompany::SESSION_KEY))->toBe('al_fadhaa')
        // The station chosen for KurdishMedicus is forgotten.
        ->and(AdminBranch::id())->toBeNull();

    $this->get('/admin')->assertOk()
        ->assertSee("Main page — Al-Fadhaa' Bureau")
        ->assertSee('This company has no stations yet, so everything shows zero.')
        // Its stations are not KurdishMedicus' stations: no station picker.
        ->assertDontSee('data-branch-switch', false);

    $this->post(route('admin.company.switch'), ['company' => 'kurdmedicus'])->assertRedirect('/admin');
    expect(session()->has(AdminCompany::SESSION_KEY))->toBeFalse();
    $this->get('/admin')->assertOk()->assertDontSee('This company has no stations yet');
});

it('finds no station and no stock in a company without stations, but keeps the screens', function () {
    $scope = app(BranchScopeResolver::class);
    $scope->focusNoBranches($this->admin);

    expect($scope->branchIdsFor($this->admin, 'stock.view'))->toBe([])
        ->and($scope->allows($this->admin, 'stock.view', $this->dhk))->toBeFalse()
        // The boxes stay on the Main page, they just show zero.
        ->and($scope->hasAny($this->admin, 'stock.view'))->toBeTrue()
        ->and(app(StockQuery::class)->units($this->admin)->count())->toBe(0)
        ->and(app(StockQuery::class)->valueByCurrency($scope->branchIdsFor($this->admin, 'stock.view')))->toBe([])
        ->and(app(OverviewService::class)->facts($this->admin, OverviewMetric::Stock, now()->startOfMonth(), now())->isEmpty())->toBeTrue();

    $scope->focusNoBranches(null);
    expect($scope->branchIdsFor($this->admin, 'stock.view'))->toBeNull()
        ->and(app(StockQuery::class)->units($this->admin)->count())->toBe(1);
});

it('refuses an unknown company and asks guests to sign in', function () {
    $this->actingAs($this->admin)->post(route('admin.company.switch'), ['company' => 'other'])->assertNotFound();

    auth()->logout();
    $this->post(route('admin.company.switch'), ['company' => 'al_fadhaa'])->assertRedirect();
    expect(session()->has(AdminCompany::SESSION_KEY))->toBeFalse();
});
