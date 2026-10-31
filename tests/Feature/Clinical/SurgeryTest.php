<?php

use App\Enums\CustomerType;
use App\Enums\MovementType;
use App\Enums\Role;
use App\Enums\StockStatus;
use App\Enums\SurgeryStatus;
use App\Exceptions\Domain\StockRuleViolation;
use App\Models\Customer;
use App\Models\Patient;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\StockUnit;
use App\Models\Surgery;
use App\Models\SurgeryType;
use App\Services\Clinical\SurgeryService;
use Database\Seeders\InventoryBaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Surgery logging (Module 10): draft sync (idempotent), complete deducts stock, warnings,
 * expired blocked, cancel reverses.
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    $this->surgeries = app(SurgeryService::class);
    $this->dhk = branch('DHK');
    $this->bgw = branch('BGW');
    $this->admin = userWithRole(Role::SuperAdmin, [$this->dhk]);
    $this->doctor = userWithRole(Role::Doctor, [$this->dhk]);
    $this->staff = userWithRole(Role::BranchStaff, [$this->dhk]);
    $this->hospital = Customer::query()->create(['code' => 'HSP-1', 'type' => CustomerType::Hospital, 'name' => ['en' => 'Azadi']]);
    $this->type = SurgeryType::query()->create(['code' => 'TKA', 'name' => ['en' => 'Total knee']]);
    $this->implant = Product::factory()->create(['sku' => 'KN-1']);
    $this->screw = Product::factory()->lot()->create(['sku' => 'SC-1']);

    stockIn($this->dhk, $this->admin, [
        ['product_id' => $this->implant->id, 'serial' => 'SN-1'],
        ['product_id' => $this->implant->id, 'serial' => 'SN-2'],
        ['product_id' => $this->screw->id, 'lot' => 'L1', 'quantity' => 20, 'unit_cost' => '2'],
    ]);
    stockIn($this->bgw, $this->admin, [['product_id' => $this->implant->id, 'serial' => 'SN-BGW']]);
});

function surgeryPayload($test, array $overrides = []): array
{
    return $overrides + [
        'client_uuid' => (string) Str::uuid(),
        'branch_id' => $test->dhk->id,
        'surgery_date' => now()->toDateString(),
        'surgery_type_id' => $test->type->id,
        'customer_id' => $test->hospital->id,
        'new_patient' => ['full_name' => 'Ahmad Karim', 'phone' => '0750 123 4567', 'age' => 64, 'gender' => 'male'],
        'laterality' => 'left',
        'items' => [['raw' => 'SN-1'], ['raw' => 'SC-1', 'quantity' => 3]],
    ];
}

it('completes a surgery: implant used, lot reduced, patient created and linked', function () {
    $surgery = $this->surgeries->sync(surgeryPayload($this, ['complete' => true]), $this->doctor);

    expect($surgery->status)->toBe(SurgeryStatus::Completed)
        ->and($surgery->number)->toStartWith('SRG')
        ->and($surgery->problems)->toBeNull()
        ->and(StockUnit::query()->where('serial', 'SN-1')->value('status'))->toBe(StockStatus::UsedInSurgery)
        ->and(StockUnit::query()->where('serial', 'SN-1')->value('customer_id'))->toBe($this->hospital->id)
        ->and(StockBatch::query()->where('lot', 'L1')->value('quantity'))->toBe(17)
        ->and(StockMovement::query()->where('type', MovementType::SurgeryUse)->count())->toBe(2)
        ->and($surgery->patient->full_name)->toBe('Ahmad Karim')
        ->and($surgery->patient->primary_doctor_id)->toBe($this->doctor->id)
        ->and($surgery->leadDoctor()->id)->toBe($this->doctor->id)
        ->and($surgery->items()->whereNotNull('unit_cost')->count())->toBe(2);
});

it('keeps a draft with the reasons when something is missing, and completes once fixed', function () {
    $payload = surgeryPayload($this, ['customer_id' => null, 'items' => [['raw' => 'SN-1'], ['raw' => 'NOPE-404']], 'complete' => true]);
    $surgery = $this->surgeries->sync($payload, $this->doctor);

    expect($surgery->status)->toBe(SurgeryStatus::Draft)
        ->and($surgery->problems)->toHaveCount(2)
        ->and(StockUnit::query()->where('serial', 'SN-1')->value('status'))->toBe(StockStatus::InStock)
        // The typed patient waits on the draft; no patient file yet.
        ->and(Patient::query()->count())->toBe(0)
        ->and($surgery->patientName())->toBe('Ahmad Karim');

    // Same draft, fixed (same client_uuid): updated, not duplicated.
    $surgery = $this->surgeries->sync(['customer_id' => $this->hospital->id, 'items' => [['raw' => 'SN-1']]] + $payload, $this->doctor);

    expect($surgery->status)->toBe(SurgeryStatus::Completed)
        ->and(Surgery::query()->count())->toBe(1)
        ->and(Patient::query()->count())->toBe(1);
});

it('needs the patient name to complete', function () {
    $surgery = $this->surgeries->sync(surgeryPayload($this, ['new_patient' => ['full_name' => ''], 'complete' => true]), $this->doctor);

    expect($surgery->status)->toBe(SurgeryStatus::Draft)
        ->and($surgery->problems)->toBe(['Enter the patient name.'])
        ->and(Patient::query()->count())->toBe(0);
});

it('ignores a replay of a completed surgery', function () {
    $payload = surgeryPayload($this, ['complete' => true]);
    $first = $this->surgeries->sync($payload, $this->doctor);
    $again = $this->surgeries->sync($payload, $this->doctor);

    expect($again->id)->toBe($first->id)
        ->and(StockMovement::query()->where('type', MovementType::SurgeryUse)->count())->toBe(2);
});

it('reuses an existing patient with the same phone and name', function () {
    $this->surgeries->sync(surgeryPayload($this, ['complete' => true, 'items' => [['raw' => 'SN-1']]]), $this->doctor);
    $this->surgeries->sync(surgeryPayload($this, ['complete' => true, 'items' => [['raw' => 'SN-2']],
        'new_patient' => ['full_name' => 'ahmad karim', 'phone' => '+964 750 123 4567']]), $this->doctor);

    expect(Patient::query()->count())->toBe(1);
});

it('warns about an implant from another branch, and blocks used or expired ones', function () {
    $surgery = $this->surgeries->sync(surgeryPayload($this, ['complete' => true, 'items' => [['raw' => 'SN-BGW']]]), $this->admin);

    expect($surgery->status)->toBe(SurgeryStatus::Completed)
        ->and($surgery->items()->first()->warnings)->toBe([SurgeryService::WARN_NOT_IN_BRANCH]);

    $again = $this->surgeries->sync(surgeryPayload($this, ['complete' => true, 'items' => [['raw' => 'SN-BGW']]]), $this->admin);
    expect($again->status)->toBe(SurgeryStatus::Draft)->and($again->problems[0])->toContain('already used');

    DB::table('stock_units')->where('serial', 'SN-2')->update(['expiry_date' => now()->subDay()->toDateString()]);
    $expired = $this->surgeries->sync(surgeryPayload($this, ['complete' => true, 'items' => [['raw' => 'SN-2']]]), $this->doctor);
    expect($expired->status)->toBe(SurgeryStatus::Draft);

    // A reason is not enough without the override permission: it stays a draft with the reason.
    $still = $this->surgeries->sync(surgeryPayload($this, ['client_uuid' => $expired->client_uuid, 'complete' => true, 'items' => [['raw' => 'SN-2']], 'override_reason' => 'Patient on the table']), $this->doctor);
    expect($still->status)->toBe(SurgeryStatus::Draft)->and($still->problems)->toHaveCount(1)
        ->and(fn () => $this->surgeries->complete($still, $this->doctor, 'Patient on the table'))->toThrow(StockRuleViolation::class);

    // …the Super Admin can.
    $done = $this->surgeries->sync(surgeryPayload($this, ['client_uuid' => $expired->client_uuid, 'complete' => true, 'items' => [['raw' => 'SN-2']], 'override_reason' => 'Patient on the table']), $this->admin);
    expect($done->status)->toBe(SurgeryStatus::Completed)
        ->and(StockMovement::query()->where('type', MovementType::SurgeryUse)->where('is_override', true)->count())->toBe(1);
});

it('cancels a completed surgery and puts everything back', function () {
    $surgery = $this->surgeries->sync(surgeryPayload($this, ['complete' => true]), $this->doctor);

    expect(fn () => $this->surgeries->cancel($surgery, 'Wrong patient', $this->doctor))->toThrow(AuthorizationException::class);

    $this->surgeries->cancel($surgery, 'Wrong patient', $this->admin);

    expect($surgery->fresh()->status)->toBe(SurgeryStatus::Cancelled)
        ->and(StockUnit::query()->where('serial', 'SN-1')->value('status'))->toBe(StockStatus::InStock)
        ->and(StockBatch::query()->where('lot', 'L1')->where('location_type', 'shelf')->sum('quantity'))->toEqual(20)
        ->and(StockMovement::query()->where('type', MovementType::SurgeryReversal)->count())->toBe(2)
        ->and($surgery->items()->whereNotNull('reversed_at')->count())->toBe(2);

    // the serial can be used again
    $again = $this->surgeries->sync(surgeryPayload($this, ['complete' => true, 'items' => [['raw' => 'SN-1']]]), $this->doctor);
    expect($again->status)->toBe(SurgeryStatus::Completed);
});

it('lets only users allowed in the branch log surgeries', function () {
    expect(fn () => $this->surgeries->sync(surgeryPayload($this), $this->staff))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->surgeries->sync(surgeryPayload($this, ['branch_id' => $this->bgw->id]), $this->doctor))->toThrow(AuthorizationException::class);
});

it('syncs drafts from the phone endpoint', function () {
    $this->actingAs($this->doctor);
    $draft = surgeryPayload($this, ['complete' => true]);

    $response = $this->postJson('/app/sync/surgeries', ['drafts' => [$draft]])->assertOk()->json();
    $this->postJson('/app/sync/surgeries', ['drafts' => [$draft]])->assertOk();

    expect($response['accepted'])->toBe([$draft['client_uuid']])
        ->and($response['results'][0]['status'])->toBe('completed')
        ->and(Surgery::query()->count())->toBe(1);
});
