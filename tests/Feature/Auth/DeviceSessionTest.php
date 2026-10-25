<?php

use App\Enums\Role;
use App\Models\UserSession;
use App\Services\Auth\SessionRegistry;
use App\Services\Users\UserService;

it('registers the device on the first authenticated request', function () {
    $doctor = userWithRole(Role::Doctor, [branch('DHK')]);

    $this->actingAs($doctor)
        ->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36')
        ->get('/app')
        ->assertOk();

    $session = UserSession::query()->where('user_id', $doctor->id)->sole();

    expect($session->device_label)->toBe('Chrome · Android')
        ->and($session->revoked_at)->toBeNull();
});

it('logs out a revoked device on its next request', function () {
    $doctor = userWithRole(Role::Doctor, [branch('DHK')]);

    $this->actingAs($doctor)->get('/app')->assertOk();

    $session = UserSession::query()->where('user_id', $doctor->id)->sole();
    app(SessionRegistry::class)->revoke($session, $doctor);

    $this->get('/app')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('logs out every device when a user is deactivated', function () {
    $admin = userWithRole(Role::SuperAdmin);
    $doctor = userWithRole(Role::Doctor, [branch('DHK')]);

    $this->actingAs($doctor)->get('/app')->assertOk();

    app(UserService::class)->deactivate($doctor, $admin);

    expect(UserSession::query()->where('user_id', $doctor->id)->whereNull('revoked_at')->count())->toBe(0);

    $this->get('/app')->assertRedirect(route('login'));
});

it('logs out after the inactivity timeout', function () {
    $doctor = userWithRole(Role::Doctor, [branch('DHK')], ['session_timeout_minutes' => 10]);

    $this->actingAs($doctor)->get('/app')->assertOk();

    $this->travel(11)->minutes();

    $this->get('/app')->assertRedirect(route('login'));
    $this->assertGuest();
});
