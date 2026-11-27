<?php

use App\Enums\Role;
use App\Models\Product;
use App\Services\Settings\SettingsRepository;
use Database\Seeders\InventoryBaseSeeder;
use Filament\Facades\Filament;

/*
 * Clicking the side menu swaps only the page (the menu does not blink), and lists show their page
 * numbers above the rows as well as below them.
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    app(SettingsRepository::class)->set('security.require_admin_mfa', false);
    $this->actingAs(userWithRole(Role::SuperAdmin, [branch('DHK')]));
});

it('opens admin pages without reloading the side menu, but not prints, files or the phone app', function () {
    $panel = Filament::getPanel('admin');

    expect($panel->hasSpaMode())->toBeTrue()
        ->and($panel->getSpaUrlExceptions())->toContain(url('/print/*'), url('/attachments/*'), url('/app/*'), url('/company'));
});

it('shows the page numbers above and below a list', function () {
    Product::factory()->count(30)->create();

    $html = $this->get('/admin/products')->assertOk()->getContent();

    expect(substr_count($html, 'ot-pagination-top'))->toBeGreaterThanOrEqual(1)
        ->and(substr_count($html, 'fi-pagination-overview'))->toBe(2);
});
