<?php

use App\Enums\Company;
use App\Enums\Governorate;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
use App\Services\Companies\CompanySetupService;
use App\Services\Settings\SettingsRepository;
use App\Support\CompanyContext;
use Database\Seeders\InventoryBaseSeeder;

/*
 * Two companies in one system (same owner): KurdishMedicus in the main database, Al-Fadhaa' Bureau
 * in its own. Their stations, stock and settings never mix. Al-Fadhaa' Bureau has no login of its own:
 * KurdishMedicus' super admin opens it with the button next to the company name.
 * Here Al-Fadhaa' Bureau's database is a SQLite file.
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    $this->admin = userWithRole(Role::SuperAdmin, [branch('DHK')], ['username' => 'rajab']);
    $this->file = tempnam(sys_get_temp_dir(), 'alfadhaa').'.sqlite';
    touch($this->file);
});

afterEach(function () {
    CompanyContext::activate(Company::main());
    @unlink($this->file);
});

function withAlFadhaa(string $file): void
{
    config([
        'orthotrack.companies.al_fadhaa.database' => $file,
        'database.connections.company_al_fadhaa' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true],
    ]);
}

it('offers a company only when it has a database', function () {
    expect(CompanyContext::available())->toBe([Company::KurdishMedicus]);
    $this->actingAs($this->admin)->get('/admin')->assertOk()->assertDontSee('data-company-switch', false);

    withAlFadhaa($this->file);

    expect(CompanyContext::available())->toBe([Company::KurdishMedicus, Company::AlFadhaa]);
    // One button, next to the company name: the other company.
    $this->get('/admin')->assertOk()
        ->assertSee('data-company-switch', false)
        ->assertSee('value="al_fadhaa"', false)
        ->assertDontSee('value="kurdmedicus"', false);
});

it('shows each company only the governorates it works in', function () {
    withAlFadhaa($this->file);

    expect(CompanyContext::governorates())->toHaveCount(19);

    CompanyContext::activate(Company::AlFadhaa);

    expect(CompanyContext::governorates())->toBe([Governorate::Baghdad, Governorate::Babil, Governorate::Karbala]);
});

it('gives the other company its own database, sign-in, cache, files and backups', function () {
    withAlFadhaa($this->file);
    $main = ['session' => config('session.cookie'), 'cache' => config('cache.prefix'), 'files' => config('filesystems.disks.local.root')];

    CompanyContext::activate(Company::AlFadhaa);

    expect(CompanyContext::current())->toBe(Company::AlFadhaa)
        ->and(config('database.default'))->toBe('company_al_fadhaa')
        ->and(config('session.cookie'))->toBe($main['session'].'-al_fadhaa')
        ->and(config('cache.prefix'))->toBe($main['cache'].'al_fadhaa-')
        ->and(config('cache.stores.file.path'))->toEndWith('/companies/al_fadhaa')
        ->and(config('filesystems.disks.local.root'))->toBe($main['files'].'/companies/al_fadhaa')
        ->and(config('backup.backup.name'))->toEndWith('-al_fadhaa')
        ->and(config('backup.backup.source.databases'))->toBe(['company_al_fadhaa'])
        ->and(config('permission.cache.key'))->toEndWith('.al_fadhaa');

    CompanyContext::activate(Company::main());

    expect(config('database.default'))->toBe('pgsql')
        ->and(config('session.cookie'))->toBe($main['session'])
        ->and(config('filesystems.disks.local.root'))->toBe($main['files']);
});

it('sets up Al-Fadhaa\' Bureau with its own stations, and the data never mixes', function () {
    withAlFadhaa($this->file);
    Product::factory()->create(['sku' => 'KM-ONLY']);
    $mainStations = Branch::query()->count();

    $result = CompanyContext::run(Company::AlFadhaa, function () {
        $result = app(CompanySetupService::class)->setup();
        Product::factory()->create(['sku' => 'AF-ONLY']);

        expect(Branch::query()->orderBy('code')->pluck('code')->all())->toBe(['BBL', 'BGD', 'KRB'])
            ->and(Branch::query()->where('code', 'KRB')->value('governorate'))->toBe(Governorate::Karbala)
            ->and(Product::query()->pluck('sku')->all())->toBe(['AF-ONLY'])
            ->and(app(SettingsRepository::class)->get('company.name'))->toBe("Al-Fadhaa' Bureau");

        // A name left at the system default is replaced, a chosen one is kept.
        app(SettingsRepository::class)->set('company.name', 'OrthoTrack');
        app(CompanySetupService::class)->setup();
        expect(app(SettingsRepository::class)->get('company.name'))->toBe("Al-Fadhaa' Bureau");

        // Running it again adds nothing.
        expect(app(CompanySetupService::class)->setup()['stations'])->toBe(0);

        return $result;
    });

    expect($result['stations'])->toBe(3)
        // Back in KurdishMedicus: its own stations and products only.
        ->and(Branch::query()->count())->toBe($mainStations)
        ->and(Branch::query()->whereIn('code', ['BGD', 'KRB', 'BBL'])->exists())->toBeFalse()
        ->and(Product::query()->pluck('sku')->all())->toBe(['KM-ONLY']);
});

it('reads the company of a command from ORTHOTRACK_COMPANY', function () {
    withAlFadhaa($this->file);

    putenv(CompanyContext::ENV.'=al_fadhaa');
    CompanyContext::boot(app());
    expect(CompanyContext::current())->toBe(Company::AlFadhaa)->and(config('database.default'))->toBe('company_al_fadhaa');

    putenv(CompanyContext::ENV.'=nonsense');
    CompanyContext::boot(app());
    expect(CompanyContext::current())->toBe(Company::KurdishMedicus);

    putenv(CompanyContext::ENV);
});

it('opens Al-Fadhaa\' Bureau from KurdishMedicus with the button: signed in, no login of its own', function () {
    withAlFadhaa($this->file);
    CompanyContext::run(Company::AlFadhaa, fn () => app(CompanySetupService::class)->setup());

    // In KurdishMedicus: the button gives a one-minute signed link and the company cookie.
    $response = $this->actingAs($this->admin)->post(route('admin.company.switch'), ['company' => 'al_fadhaa'])
        ->assertPlainCookie(CompanyContext::COOKIE, 'al_fadhaa');
    $enter = $response->headers->get('Location');
    expect($enter)->toContain('/company/enter')->toContain('signature=');

    // Arriving in Al-Fadhaa' Bureau: the same person, signed in, Super Admin there.
    auth()->logout();
    CompanyContext::activate(Company::AlFadhaa);

    $this->get($enter)->assertRedirect('/admin');
    $there = User::query()->where('username', 'rajab')->first();
    expect($there)->not->toBeNull()
        ->and($there->isSuperAdmin())->toBeTrue()
        ->and($there->all_branches)->toBeTrue();
    $this->assertAuthenticatedAs($there);

    // The link works once, and only when signed.
    auth()->logout();
    $this->get($enter)->assertForbidden();
    $this->get(route('company.enter', ['company' => 'al_fadhaa', 'username' => 'intruder', 'once' => 'x']))->assertForbidden();
    expect(User::query()->where('username', 'intruder')->exists())->toBeFalse();

    // Back to KurdishMedicus: the cookie goes.
    $this->actingAs($there)->post(route('admin.company.switch'), ['company' => 'kurdmedicus'])
        ->assertRedirect('/admin')
        ->assertCookieExpired(CompanyContext::COOKIE);
});

it('sends anyone not signed in to Al-Fadhaa\' Bureau back to KurdishMedicus\' sign-in', function () {
    withAlFadhaa($this->file);
    CompanyContext::run(Company::AlFadhaa, fn () => app(CompanySetupService::class)->setup());
    CompanyContext::activate(Company::AlFadhaa);

    $this->get('/admin')->assertRedirect('/admin/login')->assertCookieExpired(CompanyContext::COOKIE);
    $this->get('/app')->assertRedirect('/admin/login');
});

it('offers the button only to people who may open the other company, never on the sign-in page', function () {
    withAlFadhaa($this->file);
    $staff = userWithRole(Role::Accountant, [branch('DHK')], ['username' => 'accountant']);

    $this->get('/admin/login')->assertOk()->assertDontSee('data-company-switch', false);

    $this->actingAs($staff)->get('/admin')->assertOk()->assertDontSee('data-company-switch', false);
    $this->post(route('admin.company.switch'), ['company' => 'al_fadhaa'])->assertForbidden();

    $this->actingAs($this->admin)->post(route('admin.company.switch'), ['company' => 'other'])->assertNotFound();
});

it('does not open a company without a database', function () {
    $this->actingAs($this->admin)->post(route('admin.company.switch'), ['company' => 'al_fadhaa'])->assertNotFound();
});
