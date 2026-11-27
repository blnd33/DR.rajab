<?php

use App\Enums\Role;
use App\Services\Settings\SettingsRepository;
use App\Support\SectionRegistry;
use Database\Seeders\InventoryBaseSeeder;

/*
 * Each section of the side menu has its own home page with a window for every entry; its name in
 * the menu opens it. Back / forward arrows in the top bar; a thinner menu.
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    app(SettingsRepository::class)->set('security.require_admin_mfa', false);
    $this->admin = userWithRole(Role::SuperAdmin, [branch('DHK')]);
});

it('opens a section\'s home page with a window for each entry', function () {
    $this->actingAs($this->admin);

    $this->get('/admin/section?section=company-agents')->assertOk()
        ->assertSee('Company Agents')
        ->assertSeeInOrder(['data-section-windows', 'Distributors', 'Sub Distributors', 'Direct Sellers', 'Representatives', 'Partners', 'Technicians', 'Employees', 'Drivers'], false);

    foreach (array_keys(SectionRegistry::SECTIONS) as $key) {
        $this->get('/admin/section?section='.$key)->assertOk();
    }

    $this->get('/admin/section?section=nonsense')->assertNotFound();
});

it('links each section name in the menu to its page, in every language', function (string $locale) {
    $this->admin->forceFill(['locale' => $locale])->save();
    $this->actingAs($this->admin->fresh());

    $html = $this->get('/admin')->assertOk()->getContent();

    expect($html)->toContain('ot-section-link')
        ->toContain(e(SectionRegistry::url(__('Company agents'))))
        ->toContain('data-history-back')
        ->toContain('data-history-forward');
})->with(['en', 'ar', 'ckb']);

it('shows only the entries the user may open', function () {
    $this->actingAs(userWithRole(Role::Accountant, [branch('DHK')]));

    // No company agents for an accountant: the section's page does not exist for them.
    $this->get('/admin/section?section=company-agents')->assertNotFound();
    $this->get('/admin/section?section=finance')->assertOk();
});
