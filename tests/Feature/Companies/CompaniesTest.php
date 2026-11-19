<?php

use App\Enums\Company;
use App\Enums\Governorate;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\Product;
use App\Services\Companies\CompanySetupService;
use App\Services\Settings\SettingsRepository;
use App\Support\CompanyContext;
use Database\Seeders\InventoryBaseSeeder;

/*
 * Two companies in one system (same owner): KurdishMedicus in the main database, Al-Fadhaa' Bureau
 * in its own. Their staff, stations, stock and settings never mix; the top bar switches company.
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

it('switches company with the top-bar buttons', function () {
    withAlFadhaa($this->file);

    $this->post(route('admin.company.switch'), ['company' => 'al_fadhaa'])
        ->assertRedirect('/admin')
        ->assertPlainCookie(CompanyContext::COOKIE, 'al_fadhaa');

    $this->post(route('admin.company.switch'), ['company' => 'kurdmedicus'])
        ->assertRedirect('/admin')
        ->assertCookieExpired(CompanyContext::COOKIE);

    $this->post(route('admin.company.switch'), ['company' => 'other'])->assertNotFound();
});

it('does not switch to a company without a database', function () {
    $this->post(route('admin.company.switch'), ['company' => 'al_fadhaa'])->assertNotFound();
});
