<?php

use App\Enums\Role;
use App\Models\Activity;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;
use App\Services\Users\UserService;

beforeEach(function () {
    $this->dhk = branch('DHK');
    $this->ebl = branch('EBL');
    $this->bgw = branch('BGW');
    $this->scope = app(BranchScopeResolver::class);
});

it('defaults a scoped permission to the user\'s assigned branches', function () {
    $staff = userWithRole(Role::BranchStaff, [$this->dhk]);

    expect($this->scope->branchIdsFor($staff, 'transfers.send'))->toBe([$this->dhk->id])
        ->and($staff->canInBranch('transfers.send', $this->dhk))->toBeTrue()
        ->and($staff->canInBranch('transfers.send', $this->ebl))->toBeFalse();
});

it('gives no branches for a permission the user does not have', function () {
    $doctor = userWithRole(Role::Doctor, [$this->dhk]);

    expect($this->scope->branchIdsFor($doctor, 'transfers.send'))->toBe([])
        ->and($this->scope->hasAny($doctor, 'transfers.send'))->toBeFalse();
});

it('treats all_branches users and unscoped permissions as all branches', function () {
    $accountant = userWithRole(Role::Accountant, [], ['all_branches' => true]);
    $staff = userWithRole(Role::BranchStaff, [$this->dhk]);

    expect($this->scope->branchIdsFor($accountant, 'stock.view'))->toBeNull()
        // products.view_any is a global (unscoped) permission.
        ->and($this->scope->branchIdsFor($staff, 'products.view_any'))->toBeNull();
});

it('gives the Super Admin every branch for every permission', function () {
    $admin = userWithRole(Role::SuperAdmin);

    expect($this->scope->branchIdsFor($admin, 'transfers.send'))->toBeNull()
        ->and($admin->canInBranch('cash_boxes.close_day', $this->bgw))->toBeTrue();
});

it('lets a per-permission override narrow or widen the default scope', function () {
    // Works in DHK + EBL, but may only receive transfers in EBL and view stock everywhere.
    $staff = userWithRole(Role::BranchStaff, [$this->dhk, $this->ebl]);

    app(UserService::class)->syncPermissionBranches($staff, [
        'transfers.receive' => [$this->ebl->id],
        'stock.view' => [null],
    ]);

    $scope = app(BranchScopeResolver::class);
    $scope->flush();

    expect($scope->branchIdsFor($staff, 'transfers.receive'))->toBe([$this->ebl->id])
        ->and($scope->branchIdsFor($staff, 'stock.view'))->toBeNull()
        // Not overridden → default branches.
        ->and($scope->branchIdsFor($staff, 'transfers.send'))->toEqualCanonicalizing([$this->dhk->id, $this->ebl->id]);
});

it('gives a deactivated user nothing', function () {
    $staff = userWithRole(Role::BranchStaff, [$this->dhk], ['is_active' => false]);

    expect($this->scope->branchIdsFor($staff, 'stock.view'))->toBe([]);
});

it('filters queries and policies the same way', function () {
    $staff = userWithRole(Role::BranchStaff, [$this->dhk]);
    $staff->givePermissionTo('activity.view_any');
    $this->scope->flush();

    $inDhk = Activity::query()->create(['log_name' => 'test', 'description' => 'dhk', 'branch_id' => $this->dhk->id]);
    $inEbl = Activity::query()->create(['log_name' => 'test', 'description' => 'ebl', 'branch_id' => $this->ebl->id]);

    $visibleIds = $this->scope->apply(Activity::query(), $staff, 'activity.view_any')->pluck('id')->all();

    expect($visibleIds)->toContain($inDhk->id)->not->toContain($inEbl->id)
        ->and($staff->can('view', $inDhk))->toBeTrue()
        ->and($staff->can('view', $inEbl))->toBeFalse();
});

it('only offers open branches in selectors', function () {
    $staff = userWithRole(Role::BranchStaff, [$this->dhk, $this->ebl]);
    $this->ebl->forceFill(['status' => 'closed', 'close_reason' => 'Test'])->save();

    $ids = $this->scope->selectableBranches($staff, 'stock.view')->pluck('id')->all();

    expect($ids)->toBe([$this->dhk->id]);
});

it('never lets anyone, including the Super Admin, delete users', function () {
    $admin = userWithRole(Role::SuperAdmin);
    $other = User::factory()->create();

    expect($admin->can('delete', $other))->toBeFalse();
});
