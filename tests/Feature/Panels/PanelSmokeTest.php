<?php

use App\Enums\Role;
use App\Services\Settings\SettingsRepository;
use Filament\Facades\Filament;

/*
 * Every Phase 0 screen opens for the right people and is refused for others.
 */

it('opens every admin screen for the Super Admin', function (string $path) {
    $this->actingAs(userWithRole(Role::SuperAdmin, [branch('DHK')]))
        ->get($path)
        ->assertOk();
})->with([
    '/admin',
    '/admin/users',
    '/admin/users/create',
    '/admin/branches',
    '/admin/branches/create',
    '/admin/roles',
    '/admin/activities',
    '/admin/settings',
    '/admin/profile',
]);

it('opens a user edit and permissions page for the Super Admin', function () {
    $admin = userWithRole(Role::SuperAdmin, [branch('DHK')]);
    $staff = userWithRole(Role::BranchStaff, [branch('DHK')]);

    $this->actingAs($admin)->get("/admin/users/{$staff->id}/edit")->assertOk();
    $this->actingAs($admin)->get("/admin/users/{$staff->id}/permissions")->assertOk();
});

it('hides user management and settings from the accountant', function (string $path) {
    $this->actingAs(userWithRole(Role::Accountant, [], ['all_branches' => true]))
        ->get($path)
        ->assertForbidden();
})->with([
    '/admin/users',
    '/admin/roles',
    '/admin/settings',
]);

it('opens every ops screen for a doctor', function (string $path) {
    $this->actingAs(userWithRole(Role::Doctor, [branch('DHK')]))
        ->get($path)
        ->assertOk();
})->with([
    '/app',
    '/app/scan',
    '/app/notifications',
    '/app/profile',
]);

it('serves the localised PWA manifest and the offline page', function () {
    $this->get('/manifest.webmanifest')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/manifest+json')
        ->assertJsonPath('start_url', '/app?source=pwa')
        ->assertJsonPath('display', 'standalone');

    $this->get('/offline')->assertOk()->assertSee('ئینتەرنێت');
});

it('requires two-factor setup for the Super Admin when the setting is on', function () {
    app(SettingsRepository::class)->set('security.require_admin_mfa', true);
    Filament::setCurrentPanel('admin');

    $this->actingAs(userWithRole(Role::SuperAdmin))
        ->get('/admin/users')
        ->assertRedirect(Filament::getPanel('admin')->getProfileUrl());
});
