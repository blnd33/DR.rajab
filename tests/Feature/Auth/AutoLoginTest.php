<?php

use App\Enums\Role;
use App\Services\Settings\SettingsRepository;
use App\Support\AutoLogin;
use Database\Seeders\InventoryBaseSeeder;

/*
 * Test site opened without signing in (ORTHOTRACK_AUTO_LOGIN): every visitor is signed in as one
 * account. Only on a test site (ORTHOTRACK_TEST_SITE=true); the real site always asks to sign in.
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    app(SettingsRepository::class)->set('security.require_admin_mfa', true);
    $this->dhk = branch('DHK');
    $this->admin = userWithRole(Role::SuperAdmin, [$this->dhk], ['username' => 'rajab']);
    $this->staff = userWithRole(Role::BranchStaff, [$this->dhk], ['username' => 'staff.dhk']);
});

it('opens the admin panel and the phone app without signing in on a test site', function () {
    config(['orthotrack.test_site' => true, 'orthotrack.auto_login' => 'true']);

    expect(AutoLogin::active())->toBeTrue()
        ->and(AutoLogin::user()?->is($this->admin))->toBeTrue();

    // No login page, and no two-factor set-up step for the super admin.
    $this->get('/admin')->assertOk()->assertSee('Open without signing in.');
    $this->assertAuthenticatedAs($this->admin);
    $this->get('/admin/agents/drivers')->assertOk();
    $this->get('/app')->assertOk();
});

it('signs visitors in as the account named in the setting', function () {
    config(['orthotrack.test_site' => true, 'orthotrack.auto_login' => 'staff.dhk']);

    $this->get('/app')->assertOk();
    $this->assertAuthenticatedAs($this->staff);
});

it('never opens a site that is not a test site', function () {
    config(['orthotrack.test_site' => false, 'orthotrack.auto_login' => 'true']);

    expect(AutoLogin::active())->toBeFalse();
    $this->get('/admin')->assertRedirect();
    $this->assertGuest();
});

it('asks to sign in when it is switched off or the account does not exist', function (?string $value) {
    config(['orthotrack.test_site' => true, 'orthotrack.auto_login' => $value]);

    $this->get('/admin')->assertRedirect();
    $this->assertGuest();
})->with([null, '', 'false', 'nobody-like-this']);
