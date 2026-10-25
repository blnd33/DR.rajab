<?php

use App\Enums\LoginFailureReason;
use App\Enums\Role;
use App\Filament\Admin\Auth\Login;
use App\Models\LoginAttempt;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

function attemptLogin(string $login, string $password = 'Password-123')
{
    return Livewire::test(Login::class)
        ->fillForm(['login' => $login, 'password' => $password])
        ->call('authenticate');
}

it('sends each role to its panel after login', function (Role $role, string $expectedPath) {
    $user = userWithRole($role, [branch('DHK')]);

    attemptLogin($user->username)->assertHasNoFormErrors()->assertRedirect(url($expectedPath));

    $this->assertAuthenticatedAs($user);
})->with([
    'super admin' => [Role::SuperAdmin, '/admin'],
    'accountant' => [Role::Accountant, '/admin'],
    'doctor' => [Role::Doctor, '/app'],
    'cashier' => [Role::Cashier, '/app'],
    'branch staff' => [Role::BranchStaff, '/app'],
]);

it('accepts the email address instead of the username', function () {
    $user = userWithRole(Role::Doctor, [branch('DHK')], ['email' => 'doc@example.test']);

    attemptLogin('DOC@example.test')->assertRedirect(url('/app'));

    $this->assertAuthenticatedAs($user);
});

it('rejects a wrong password and records the attempt', function () {
    $user = userWithRole(Role::Doctor, [branch('DHK')]);

    attemptLogin($user->username, 'wrong-password')->assertHasFormErrors(['login']);

    $this->assertGuest();
    expect(LoginAttempt::query()->latest('id')->first())
        ->successful->toBeFalse()
        ->failure_reason->toBe(LoginFailureReason::BadCredentials)
        ->user_id->toBe($user->id);
});

it('blocks a deactivated user and records why', function () {
    $user = userWithRole(Role::Doctor, [branch('DHK')], ['is_active' => false]);

    attemptLogin($user->username)->assertHasFormErrors(['login']);

    $this->assertGuest();
    expect(LoginAttempt::query()->latest('id')->first()->failure_reason)->toBe(LoginFailureReason::Inactive);
});

it('blocks a user without access to any panel', function () {
    $user = User::factory()->create();

    attemptLogin($user->username)->assertHasFormErrors(['login']);

    $this->assertGuest();
    expect(LoginAttempt::query()->latest('id')->first()->failure_reason)->toBe(LoginFailureReason::NoPanelAccess);
});

it('records successful logins with the device and updates last login', function () {
    $user = userWithRole(Role::Cashier, [branch('EBL')]);

    attemptLogin($user->username);

    expect(LoginAttempt::query()->where('user_id', $user->id)->where('successful', true)->exists())->toBeTrue()
        ->and($user->fresh()->last_login_at)->not->toBeNull();
});

it('redirects ops users away from the admin panel to /app', function () {
    $doctor = userWithRole(Role::Doctor, [branch('DHK')]);

    $this->actingAs($doctor)->get('/admin')->assertRedirect(url('/app'));
});

it('redirects admin-only users away from the ops app to /admin', function () {
    $accountant = userWithRole(Role::Accountant, [branch('DHK')]);

    $this->actingAs($accountant)->get('/app')->assertRedirect(url('/admin'));
});

it('sends guests to the single login page', function () {
    $this->get('/app')->assertRedirect(route('login'));
    $this->get('/login')->assertRedirect(Filament::getPanel('admin')->getLoginUrl());
});
