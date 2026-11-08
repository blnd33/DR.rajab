<?php

use App\Enums\Role;
use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Storage;

/*
 * The client's test site (client, 2026-11-08): a subdomain on the same server with its own
 * empty database, a banner on every page, a command for the first login and a reset that
 * only a test site accepts.
 */

it('starts a test site empty: base data only, no demo branches, users or items', function () {
    config(['orthotrack.test_site' => true]);

    $this->seed(DatabaseSeeder::class);

    expect(Branch::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(0)
        ->and(Product::query()->count())->toBe(0)
        ->and(Spatie\Permission\Models\Role::query()->where('name', Role::SuperAdmin->value)->exists())->toBeTrue();
});

it('creates the first Super Admin from the command line', function () {
    $this->seed(DatabaseSeeder::BASE);

    $this->artisan('orthotrack:create-admin', ['username' => 'Rajab', '--name' => 'Dr. Rajab', '--locale' => 'ckb'])
        ->expectsQuestion('Password (at least 10 characters, upper and lower case letters and a number)', 'Knee-Implant-2026')
        ->expectsQuestion('Password again', 'Knee-Implant-2026')
        ->assertSuccessful();

    $user = User::query()->sole();
    expect($user->username)->toBe('rajab')
        ->and($user->name)->toBe('Dr. Rajab')
        ->and($user->locale)->toBe('ckb')
        ->and($user->all_branches)->toBeTrue()
        ->and($user->isSuperAdmin())->toBeTrue();

    // Same name again, or a weak password: refused, nothing created.
    $this->artisan('orthotrack:create-admin', ['username' => 'rajab'])->assertFailed();
    $this->artisan('orthotrack:create-admin', ['username' => 'second'])
        ->expectsQuestion('Password (at least 10 characters, upper and lower case letters and a number)', 'short')
        ->expectsQuestion('Password again', 'short')
        ->assertFailed();

    expect(User::query()->count())->toBe(1);
});

it('refuses to empty a site that is not a test site, or without the typed site name', function () {
    $this->seed(DatabaseSeeder::BASE);
    $user = userWithRole(Role::SuperAdmin);
    Storage::disk('local')->put('transfers/1/photo.jpg', 'x');

    config(['orthotrack.test_site' => false]);
    $this->artisan('orthotrack:reset-test-site', ['--force' => true])->assertFailed();

    config(['orthotrack.test_site' => true, 'app.url' => 'https://test.example.com']);
    $this->artisan('orthotrack:reset-test-site')
        ->expectsQuestion('This erases ALL data and uploaded files on test.example.com. Type test.example.com to confirm', 'app.example.com')
        ->assertFailed();

    expect($user->fresh())->not->toBeNull()
        ->and(Storage::disk('local')->exists('transfers/1/photo.jpg'))->toBeTrue();
});

it('shows the test-site banner on the admin and phone sign-in pages only on a test site', function () {
    $this->seed(DatabaseSeeder::BASE);
    $banner = 'Test site — for trying things out.';

    $this->get('/admin/login')->assertOk()->assertDontSee($banner);

    config(['orthotrack.test_site' => true]);
    $this->get('/admin/login')->assertOk()->assertSee($banner);
    $this->actingAs(userWithRole(Role::SuperAdmin))->get('/app')->assertSee($banner);
});
