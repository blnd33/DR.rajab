<?php

use App\Enums\AgentType;
use App\Enums\AttachmentKind;
use App\Enums\Role;
use App\Filament\Admin\Resources\Agents\Partners\Pages\ListPartners;
use App\Models\Attachment;
use App\Models\CompanyAgent;
use App\Services\Agents\CompanyAgentService;
use App\Services\Settings\SettingsRepository;
use App\Services\Users\TechnicianService;
use Database\Seeders\InventoryBaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Company agents: distributors, direct sellers, representatives, partners, technicians and
 * employees, kept by the super admin (agents.manage). Nobody here signs in.
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    app(SettingsRepository::class)->set('security.require_admin_mfa', false);
    $this->dhk = branch('DHK');
    $this->admin = userWithRole(Role::SuperAdmin, [$this->dhk]);
    $this->agents = app(CompanyAgentService::class);
});

it('keeps only the information of each kind of agent', function () {
    $everything = [
        'name' => 'Azad Ahmed', 'phone' => '0750 123 4567', 'city' => 'Duhok', 'persons' => 'Azad, Hozan',
        'stock' => '40 knee implants', 'value_amount' => '25000', 'value_currency' => 'USD', 'shares_percent' => '12.5',
        'profit_month_amount' => '1500', 'profit_currency' => 'USD', 'position' => 'Driver', 'salary_amount' => '900000',
        'salary_currency' => 'IQD', 'mission_fee_amount' => '50', 'mission_fee_currency' => 'USD',
    ];

    $distributor = $this->agents->create(AgentType::Distributor, $everything, $this->admin);
    $seller = $this->agents->create(AgentType::DirectSeller, $everything, $this->admin);
    $representative = $this->agents->create(AgentType::Representative, $everything, $this->admin);
    $partner = $this->agents->create(AgentType::Partner, $everything, $this->admin);
    $employee = $this->agents->create(AgentType::Employee, $everything, $this->admin);

    expect($distributor->only(['city', 'value_amount', 'salary_amount', 'persons']))
        ->toBe(['city' => 'Duhok', 'value_amount' => null, 'salary_amount' => null, 'persons' => null])
        ->and($seller->only(['city', 'persons', 'stock', 'value_amount', 'value_currency', 'salary_amount']))
        ->toBe(['city' => 'Duhok', 'persons' => 'Azad, Hozan', 'stock' => '40 knee implants', 'value_amount' => '25000.0000', 'value_currency' => 'USD', 'salary_amount' => null])
        ->and($representative->only(['city', 'salary_amount', 'salary_currency', 'mission_fee_amount', 'mission_fee_currency', 'position']))
        ->toBe(['city' => null, 'salary_amount' => '900000.0000', 'salary_currency' => 'IQD', 'mission_fee_amount' => '50.0000', 'mission_fee_currency' => 'USD', 'position' => null])
        ->and($employee->only(['position', 'salary_amount', 'city']))
        ->toBe(['position' => 'Driver', 'salary_amount' => '900000.0000', 'city' => null])
        // A partner's year is twelve months when left empty.
        ->and($partner->only(['shares_percent', 'value_amount', 'profit_month_amount', 'profit_year_amount', 'profit_currency', 'salary_amount']))
        ->toBe(['shares_percent' => '12.5000', 'value_amount' => '25000.0000', 'profit_month_amount' => '1500.0000', 'profit_year_amount' => '18000.0000', 'profit_currency' => 'USD', 'salary_amount' => null]);

    $partner = $this->agents->update($partner, ['name' => 'Azad Ahmed', 'shares_percent' => '10', 'profit_month_amount' => '1500', 'profit_year_amount' => '20000', 'profit_currency' => 'EUR'], $this->admin);
    expect($partner->profit_year_amount)->toBe('20000.0000')->and($partner->profit_currency)->toBe('EUR');
});

it('refuses wrong amounts, shares and currencies', function (array $data, string $field) {
    try {
        $this->agents->create(AgentType::Partner, $data + ['name' => 'Partner'], $this->admin);
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($field);
    }
})->with([
    'share above 100' => [['shares_percent' => '120'], 'shares_percent'],
    'negative value' => [['value_amount' => '-5', 'value_currency' => 'USD'], 'value_amount'],
    'value without currency' => [['value_amount' => '100'], 'value_currency'],
    'unknown currency' => [['profit_month_amount' => '100', 'profit_currency' => 'GBP'], 'profit_currency'],
    'no name' => [['name' => ' '], 'name'],
]);

it('lets only people with agents.manage keep agents', function () {
    $accountant = userWithRole(Role::Accountant, [$this->dhk]);

    expect(fn () => $this->agents->create(AgentType::Distributor, ['name' => 'X'], $accountant))->toThrow(AuthorizationException::class)
        ->and(userWithRole(Role::Manager, [$this->dhk])->can('agents.manage'))->toBeTrue()
        ->and($accountant->can('agents.manage'))->toBeFalse();

    $this->actingAs($accountant)->get('/admin/agents/distributors')->assertForbidden();
    $this->actingAs($accountant)->get('/admin/agents/drivers')->assertForbidden();
});

it('keeps every CV and shows it only to the super admin', function () {
    $employee = $this->agents->create(AgentType::Employee, ['name' => 'Shilan', 'position' => 'Accountant'], $this->admin,
        UploadedFile::fake()->create('shilan-cv.pdf', 120, 'application/pdf'));
    $this->agents->update($employee, ['name' => 'Shilan', 'position' => 'Senior accountant'], $this->admin,
        UploadedFile::fake()->create('shilan-cv-2026.pdf', 140, 'application/pdf'));

    $cvs = $employee->attachments()->where('kind', AttachmentKind::Cv->value)->get();
    expect($cvs)->toHaveCount(2)
        ->and($employee->fresh()->cv->original_name)->toBe('shilan-cv-2026.pdf');

    $this->actingAs($this->admin)->get(route('attachments.show', $cvs->first()))->assertOk();
    $this->actingAs(userWithRole(Role::Accountant, [$this->dhk]))->get(route('attachments.show', $cvs->first()))->assertForbidden();

    expect(fn () => $this->agents->update($employee, ['name' => 'Shilan'], $this->admin, UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')))
        ->toThrow(ValidationException::class);
});

it('links an employee to at most one system account, never a technician', function () {
    $cashier = userWithRole(Role::Cashier, [$this->dhk]);
    $technician = app(TechnicianService::class)->create(['name' => 'Karwan Ali', 'branches' => [$this->dhk->id]], $this->admin);

    $employee = $this->agents->create(AgentType::Employee, ['name' => 'Cashier Dilan', 'user_id' => $cashier->id], $this->admin);
    expect($employee->user_id)->toBe($cashier->id)
        ->and($cashier->fresh()->agentProfile->is($employee))->toBeTrue();

    expect(fn () => $this->agents->create(AgentType::Employee, ['name' => 'Again', 'user_id' => $cashier->id], $this->admin))->toThrow(ValidationException::class)
        ->and(fn () => $this->agents->create(AgentType::Employee, ['name' => 'Tech', 'user_id' => $technician->id], $this->admin))->toThrow(ValidationException::class);
});

it('keeps a technician\'s CV, position and salary next to their account', function () {
    $technicians = app(TechnicianService::class);
    $karwan = $technicians->create(['name' => 'Karwan Ali', 'phone' => '0750 111 2233', 'branches' => [$this->dhk->id]], $this->admin);

    $profile = $this->agents->saveTechnicianProfile($karwan, ['position' => 'Scrub technician', 'salary_amount' => '1200000', 'salary_currency' => 'IQD'], $this->admin,
        UploadedFile::fake()->create('karwan.pdf', 50, 'application/pdf'));

    expect($profile->type)->toBe(AgentType::Technician)
        ->and($profile->only(['name', 'phone', 'position', 'salary_amount', 'salary_currency']))
        ->toBe(['name' => 'Karwan Ali', 'phone' => '0750 111 2233', 'position' => 'Scrub technician', 'salary_amount' => '1200000.0000', 'salary_currency' => 'IQD'])
        ->and($profile->cv)->not->toBeNull();

    // Renaming or stopping the technician keeps the profile in step; the account still never signs in.
    $technicians->update($karwan, ['name' => 'Karwan A. Ali', 'phone' => '0750 999', 'is_active' => false], $this->admin);
    expect($profile->fresh()->only(['name', 'phone', 'is_active']))->toBe(['name' => 'Karwan A. Ali', 'phone' => '0750 999', 'is_active' => false])
        ->and($karwan->fresh()->can('agents.manage'))->toBeFalse()
        ->and(CompanyAgent::query()->where('user_id', $karwan->id)->count())->toBe(1);

    expect(fn () => $this->agents->create(AgentType::Technician, ['name' => 'X'], $this->admin))->toThrow(LogicException::class);
});

it('adds an agent from the admin list', function () {
    $this->actingAs($this->admin);

    Livewire::test(ListPartners::class)
        ->callAction('create', ['name' => 'Rebaz Partner', 'city' => 'Erbil', 'shares_percent' => '25', 'value_amount' => '100000', 'value_currency' => 'USD',
            'profit_month_amount' => '4000', 'profit_currency' => 'USD'])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $partner = CompanyAgent::query()->where('name', 'Rebaz Partner')->sole();
    expect($partner->type)->toBe(AgentType::Partner)
        ->and($partner->profit_year_amount)->toBe('48000.0000')
        ->and(Attachment::query()->count())->toBe(0);
});

it('opens every company-agent screen in every language', function (string $locale) {
    $employee = $this->agents->create(AgentType::Employee, ['name' => 'Shilan', 'position' => 'Accountant', 'salary_amount' => '800000', 'salary_currency' => 'IQD'], $this->admin,
        UploadedFile::fake()->create('cv.pdf', 20, 'application/pdf'));
    $partner = $this->agents->create(AgentType::Partner, ['name' => 'Rebaz', 'shares_percent' => '25', 'profit_month_amount' => '4000', 'profit_currency' => 'USD'], $this->admin);
    $seller = $this->agents->create(AgentType::DirectSeller, ['name' => 'Hozan', 'city' => 'Zakho', 'persons' => 'Hozan, Sipan'], $this->admin);
    $distributor = $this->agents->create(AgentType::Distributor, ['name' => 'Med Dist', 'city' => 'Erbil'], $this->admin);
    $representative = $this->agents->create(AgentType::Representative, ['name' => 'Dilan', 'mission_fee_amount' => '25', 'mission_fee_currency' => 'USD'], $this->admin);
    $technician = app(TechnicianService::class)->create(['name' => 'Karwan Ali', 'branches' => [$this->dhk->id]], $this->admin);
    $this->agents->saveTechnicianProfile($technician, ['position' => 'Scrub', 'salary_amount' => '1000', 'salary_currency' => 'USD'], $this->admin);

    $this->admin->forceFill(['locale' => $locale])->save();
    $this->actingAs($this->admin->fresh());

    foreach ([
        '/admin/agents/distributors', '/admin/agents/distributors/'.$distributor->id,
        '/admin/agents/direct-sellers', '/admin/agents/direct-sellers/'.$seller->id,
        '/admin/agents/representatives', '/admin/agents/representatives/'.$representative->id,
        '/admin/agents/partners', '/admin/agents/partners/'.$partner->id,
        '/admin/agents/employees', '/admin/agents/employees/'.$employee->id,
        '/admin/technicians', '/admin/technicians/'.$technician->id,
        '/admin/agents/drivers',
    ] as $path) {
        $this->get($path)->assertOk();
    }

    // A partner's page is another type's 404.
    $this->get('/admin/agents/employees/'.$partner->id)->assertNotFound();
})->with(['en', 'ar', 'ckb']);
