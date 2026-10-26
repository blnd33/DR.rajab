<?php

use App\Enums\Role;
use App\Models\Patient;
use App\Services\Clinical\PatientService;
use Database\Seeders\InventoryBaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/*
 * Patients (Module 12): sensitive data, doctors see their own patients unless granted more.
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    $this->patients = app(PatientService::class);
    $this->dhk = branch('DHK');
    $this->doctorA = userWithRole(Role::Doctor, [$this->dhk]);
    $this->doctorB = userWithRole(Role::Doctor, [$this->dhk]);
    $this->admin = userWithRole(Role::SuperAdmin, [$this->dhk]);
});

it('creates a patient with a file number and an encrypted phone found by its hash', function () {
    $patient = $this->patients->create($this->dhk, ['full_name' => 'Sara Ali', 'phone' => '٠٧٥٠ ١٢٣ ٤٥٦٧', 'age' => 40, 'gender' => 'female', 'notes' => 'Allergic to latex'], $this->doctorA);

    $raw = DB::table('patients')->where('id', $patient->id)->first();

    expect($patient->file_number)->toStartWith('PAT-DHK-')
        ->and($patient->phone)->toBe('0750 123 4567')
        ->and($raw->phone)->not->toContain('0750')
        ->and($raw->notes)->not->toContain('latex')
        ->and($patient->birth_year)->toBe((int) now()->format('Y') - 40)
        ->and($patient->age())->toBe(40)
        ->and($patient->primary_doctor_id)->toBe($this->doctorA->id)
        ->and($this->patients->findByPhone('+964 750 123 4567')?->id)->toBe($patient->id)
        ->and(Activity::query()->where('log_name', 'patients')->get()->pluck('properties')->toJson())->not->toContain('0750');
});

it('shows doctors only their own patients; the Super Admin sees all', function () {
    $mine = $this->patients->create($this->dhk, ['full_name' => 'Mine'], $this->doctorA);
    $theirs = $this->patients->create($this->dhk, ['full_name' => 'Theirs'], $this->doctorB);

    expect($this->patients->visibleTo($this->doctorA)->pluck('id')->all())->toBe([$mine->id])
        ->and($this->patients->visibleTo($this->admin)->count())->toBe(2)
        ->and($this->patients->search($this->doctorA, 'Theirs')->count())->toBe(0)
        ->and(fn () => $this->patients->update($theirs, ['full_name' => 'Changed'], $this->doctorA))->toThrow(AuthorizationException::class);
});

it('logs every opening of a patient file', function () {
    $patient = $this->patients->create($this->dhk, ['full_name' => 'Logged'], $this->doctorA);
    $this->patients->logView($patient, $this->doctorA);

    expect(Activity::query()->where('event', 'viewed')->where('subject_id', $patient->id)->where('causer_id', $this->doctorA->id)->exists())->toBeTrue();
});

it('needs the patients permission to create', function () {
    $staff = userWithRole(Role::BranchStaff, [$this->dhk]);

    expect(fn () => $this->patients->create($this->dhk, ['full_name' => 'X'], $staff))->toThrow(AuthorizationException::class)
        ->and(Patient::query()->count())->toBe(0);
});
