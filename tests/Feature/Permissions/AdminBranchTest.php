<?php

use App\Enums\Panel;
use App\Enums\Role;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;
use App\Services\Permissions\PermissionRegistry;
use App\Services\Users\TechnicianService;
use App\Support\AdminBranch;
use Database\Seeders\InventoryBaseSeeder;

/*
 * The admin top-bar branch: everything branch-based follows it (lists, pickers, actions), only
 * for the person who chose it. The Manager role holds every permission.
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    $this->dhk = branch('DHK');
    $this->ebl = branch('EBL');
    $this->manager = userWithRole(Role::Manager, [], ['all_branches' => true]);
    $this->accountant = userWithRole(Role::Accountant, [$this->dhk, $this->ebl], ['all_branches' => false]);
    $this->dhkDoctor = userWithRole(Role::Doctor, [$this->dhk], ['name' => 'Dr. Duhok']);
    $this->eblDoctor = userWithRole(Role::Doctor, [$this->ebl], ['name' => 'Dr. Erbil']);
});

it('narrows every branch-based permission to the chosen branch, for that person only', function () {
    $scope = app(BranchScopeResolver::class);

    expect($scope->branchIdsFor($this->accountant, 'expenses.view_any'))->toEqualCanonicalizing([$this->dhk->id, $this->ebl->id]);

    $scope->focus($this->accountant, $this->ebl->id);

    expect($scope->branchIdsFor($this->accountant, 'expenses.view_any'))->toBe([$this->ebl->id])
        ->and($scope->allows($this->accountant, 'expenses.view_any', $this->dhk->id))->toBeFalse()
        // Someone else in the same request (e.g. a person being notified) keeps their own scope.
        ->and($scope->branchIdsFor($this->manager, 'expenses.view_any'))->toBeNull()
        // Permissions that are not about a branch are untouched.
        ->and($scope->branchIdsFor($this->accountant, 'suppliers.view_any'))->toBeNull();

    // A branch outside the person's scope shows nothing.
    $scope->focus($this->dhkDoctor, $this->ebl->id);
    expect($scope->branchIdsFor($this->dhkDoctor, 'surgeries.view_any'))->toBe([]);
});

it('offers only the chosen branch\'s doctors and technicians', function () {
    $tech = app(TechnicianService::class)->create(['name' => 'Tech Erbil', 'branches' => [$this->ebl->id]], $this->manager);

    expect(User::doctorOptions($this->dhk->id))->toContain('Dr. Duhok')->not->toContain('Dr. Erbil')
        ->and(User::doctorOptions($this->ebl->id))->toContain('Dr. Erbil')->not->toContain('Dr. Duhok')
        ->and(User::technicianOptions($this->ebl->id))->toBe([$tech->id => 'Tech Erbil'])
        ->and(User::technicianOptions($this->dhk->id))->toBe([]);
});

it('switches the branch in the admin top bar and only to the person\'s own branches', function () {
    $this->actingAs($this->accountant);

    $this->post(route('admin.branch.switch'), ['branch_id' => $this->ebl->id])->assertRedirect();
    expect(AdminBranch::id())->toBe($this->ebl->id);

    $this->post(route('admin.branch.switch'), ['branch_id' => 'all'])->assertRedirect();
    expect(AdminBranch::id())->toBeNull();

    $other = branch('BGW');
    $this->post(route('admin.branch.switch'), ['branch_id' => $other->id])->assertForbidden();

    // The manager may pick any branch.
    expect(AdminBranch::options($this->manager)->pluck('code')->all())->toContain('BGW', 'DHK', 'EBL');
});

it('lists only the chosen branch\'s accounts on the Users screen', function () {
    $this->actingAs($this->manager);
    $this->post(route('admin.branch.switch'), ['branch_id' => $this->ebl->id]);

    $this->get('/admin/users')->assertOk()->assertSee('Dr. Erbil')->assertDontSee('Dr. Duhok');
});

it('gives the Manager every permission and the admin panel (opening Al-Fadhaa\' Bureau stays with Super Admin)', function () {
    expect($this->manager->getAllPermissions()->pluck('name')->sort()->values()->all())->toBe(collect(PermissionRegistry::names())->reject(fn (string $p) => $p === 'companies.switch')->sort()->values()->all())
        ->and($this->manager->canAccessAppPanel(Panel::Admin))->toBeTrue()
        ->and(app(BranchScopeResolver::class)->branchIdsFor($this->manager, 'reports.view'))->toBeNull();
});
