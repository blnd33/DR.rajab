<?php

use App\Enums\CustomerType;
use App\Enums\Panel;
use App\Enums\Role;
use App\Enums\SurgeryDoctorRole;
use App\Exceptions\Domain\StockRuleViolation;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SurgeryType;
use App\Services\Clinical\SurgeryService;
use App\Services\Graphs\GraphContext;
use App\Services\Graphs\GraphRegistry;
use App\Services\Money\FxService;
use App\Services\Permissions\RoleService;
use App\Services\Users\TechnicianService;
use Database\Seeders\InventoryBaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role as SpatieRole;

/*
 * Technicians: accounts that never sign in and hold no permissions, picked on surgeries
 * (instead of assistants) and counted in the Technicians graphs.
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    $this->dhk = branch('DHK');
    $this->admin = userWithRole(Role::SuperAdmin, [$this->dhk]);
    $this->doctor = userWithRole(Role::Doctor, [$this->dhk]);
    app(FxService::class)->setRate('USD', 'IQD', '1460', $this->admin);
    app(FxService::class)->setRate('EUR', 'USD', '1.10', $this->admin);
    $this->hospital = Customer::query()->create(['code' => 'HSP-1', 'type' => CustomerType::Hospital, 'name' => ['en' => 'Azadi']]);
    $this->knee = SurgeryType::query()->create(['code' => 'TKA', 'name' => ['en' => 'Total knee']]);
    $this->implant = Product::factory()->create(['sku' => 'KN-1']);
    stockIn($this->dhk, $this->admin, [
        ['product_id' => $this->implant->id, 'serial' => 'SN-1'],
        ['product_id' => $this->implant->id, 'serial' => 'SN-2'],
    ]);

    $this->technicians = app(TechnicianService::class);
    $this->karwan = $this->technicians->create(['name' => 'Karwan Ali', 'phone' => '0750 111 2233'], $this->admin);
    $this->rebin = $this->technicians->create(['name' => 'Rebin Omar'], $this->admin);
});

function technicianSurgery($test, string $serial, array $technicians, array $overrides = []): array
{
    return $overrides + [
        'client_uuid' => (string) Str::uuid(),
        'branch_id' => $test->dhk->id,
        'surgery_date' => now()->toDateString(),
        'surgery_type_id' => $test->knee->id,
        'customer_id' => $test->hospital->id,
        'technician_ids' => array_map(fn ($t) => $t->id, $technicians),
        'items' => [['raw' => $serial]],
        'complete' => true,
    ];
}

it('makes technician accounts that cannot sign in or do anything', function () {
    expect($this->karwan->isTechnician())->toBeTrue()
        ->and($this->karwan->canAccessAppPanel(Panel::Admin))->toBeFalse()
        ->and($this->karwan->canAccessAppPanel(Panel::Ops))->toBeFalse()
        ->and($this->karwan->getAllPermissions())->toBeEmpty();

    // Even a permission given by hand does nothing.
    $this->karwan->givePermissionTo('ops.access');
    expect($this->karwan->fresh()->can('ops.access'))->toBeFalse()
        ->and($this->karwan->fresh()->canAccessAppPanel(Panel::Ops))->toBeFalse();

    // The technician role keeps no permissions when edited.
    $role = SpatieRole::findByName(Role::Technician->value, 'web');
    app(RoleService::class)->save($role, $role->name, ['ops.access', 'stock.view']);
    expect($role->fresh()->permissions)->toBeEmpty();

    // Only people allowed to manage technicians add them.
    expect(fn () => $this->technicians->create(['name' => 'X'], $this->doctor))->toThrow(AuthorizationException::class);
});

it('logs technicians on a surgery and refuses anyone who is not a technician', function () {
    $surgery = app(SurgeryService::class)->sync(technicianSurgery($this, 'SN-1', [$this->karwan, $this->rebin]), $this->doctor);

    expect($surgery->technicians()->pluck('name')->sort()->values()->all())->toBe(['Karwan Ali', 'Rebin Omar'])
        ->and($surgery->doctors()->wherePivot('role', SurgeryDoctorRole::Lead->value)->value('users.id'))->toBe($this->doctor->id);

    expect(fn () => app(SurgeryService::class)->sync(technicianSurgery($this, 'SN-2', [$this->admin]), $this->doctor))
        ->toThrow(StockRuleViolation::class);
});

it('shows what each technician did in the graphs', function () {
    $surgeries = app(SurgeryService::class);
    $surgeries->sync(technicianSurgery($this, 'SN-1', [$this->karwan, $this->rebin], ['fee_amount' => '1000', 'fee_currency' => 'USD']), $this->doctor);
    $surgeries->sync(technicianSurgery($this, 'SN-2', [$this->karwan], ['fee_amount' => '1460000', 'fee_currency' => 'IQD']), $this->doctor);

    $registry = app(GraphRegistry::class);
    $context = GraphContext::make($this->admin, ['currency' => 'USD']);

    $count = ($registry->find('surgeries-by-technician')->build)($context);
    $counts = array_combine($count->labels, $count->series[0]['values']);
    expect($counts)->toBe(['Karwan Ali' => 2.0, 'Rebin Omar' => 1.0]);

    $money = ($registry->find('technician-surgery-money')->build)($context);
    $amounts = array_combine($money->labels, $money->series[0]['values']);
    expect($amounts)->toBe(['Karwan Ali' => 2000.0, 'Rebin Omar' => 1000.0]);

    $monthly = ($registry->find('technician-surgeries-per-month')->build)($context);
    expect(array_column($monthly->series, 'label'))->toBe(['Karwan Ali', 'Rebin Omar']);

    $types = ($registry->find('technician-surgery-types')->build)($context);
    expect(array_combine($types->labels, $types->series[0]['values']))->toBe(['Total knee' => 2.0]);
});
