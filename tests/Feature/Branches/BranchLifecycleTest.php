<?php

use App\Enums\BranchStatus;
use App\Enums\Role;
use App\Exceptions\Domain\BranchClosureBlocked;
use App\Models\Branch;
use App\Services\Branches\BranchService;
use App\Services\Numbering\DocumentNumberGenerator;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->service = app(BranchService::class);
    $this->admin = userWithRole(Role::SuperAdmin);
});

it('blocks closing while active users only work in that branch', function () {
    $branch = branch('EBL');
    userWithRole(Role::Cashier, [$branch]);

    expect(fn () => $this->service->close($branch, 'Moving', $this->admin))
        ->toThrow(BranchClosureBlocked::class);

    expect($branch->fresh()->status)->toBe(BranchStatus::Active);
});

it('closes a branch once users are reassigned, keeping it for reports', function () {
    $branch = branch('EBL');
    $other = branch('DHK');
    $cashier = userWithRole(Role::Cashier, [$branch, $other], ['primary_branch_id' => $other->id]);

    $this->service->close($branch, 'Lease ended', $this->admin);

    $branch->refresh();
    expect($branch->status)->toBe(BranchStatus::Closed)
        ->and($branch->close_reason)->toBe('Lease ended')
        ->and($branch->closed_by)->toBe($this->admin->id)
        ->and(Branch::query()->active()->pluck('id'))->not->toContain($branch->id)
        ->and(Branch::query()->pluck('id'))->toContain($branch->id);

    $this->service->reopen($branch, $this->admin);
    expect($branch->fresh()->status)->toBe(BranchStatus::Active);
});

it('requires a reason to close', function () {
    expect(fn () => $this->service->close(branch('BGW'), '  ', $this->admin))
        ->toThrow(ValidationException::class);
});

it('only hard-deletes a branch with no records', function () {
    $empty = Branch::factory()->create(['code' => 'TMP']);
    $used = branch('DHK');
    userWithRole(Role::BranchStaff, [$used]);

    expect($this->service->canHardDelete($empty))->toBeTrue()
        ->and($this->service->canHardDelete($used))->toBeFalse();

    $this->service->hardDelete($empty);
    expect(Branch::query()->find($empty->id))->toBeNull();

    expect(fn () => $this->service->hardDelete($used))->toThrow(ValidationException::class);
});

it('stores translatable names and drops empty languages', function () {
    $branch = $this->service->create([
        'code' => 'slm',
        'name' => ['en' => 'Sulaymaniyah', 'ar' => 'السليمانية', 'ckb' => ''],
        'default_currency' => 'IQD',
    ]);

    app()->setLocale('ckb');

    expect($branch->code)->toBe('SLM')
        ->and($branch->getTranslations('name'))->toBe(['en' => 'Sulaymaniyah', 'ar' => 'السليمانية'])
        // Sorani missing → falls back to English.
        ->and($branch->name)->toBe('Sulaymaniyah');
});

it('generates gap-free document numbers per branch and year', function () {
    $generator = app(DocumentNumberGenerator::class);
    $dhk = branch('DHK');

    expect($generator->next('TRF', $dhk, 2026))->toBe('TRF-DHK-2026-00001')
        ->and($generator->next('TRF', $dhk, 2026))->toBe('TRF-DHK-2026-00002')
        ->and($generator->next('TRF', branch('EBL'), 2026))->toBe('TRF-EBL-2026-00001')
        ->and($generator->next('PUR', null, 2026))->toBe('PUR-2026-00001');
});
