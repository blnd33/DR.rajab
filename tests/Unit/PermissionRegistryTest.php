<?php

use App\Enums\Role;
use App\Services\Permissions\PermissionRegistry;

it('uses domain.action names', function () {
    foreach (PermissionRegistry::names() as $name) {
        expect($name)->toMatch('/^[a-z_]+\.[a-z_]+$/');
    }
});

it('has a template for every built-in role', function () {
    expect(array_keys(PermissionRegistry::roleTemplates()))->toEqualCanonicalizing(Role::values());
});

it('only references existing permissions in role templates', function () {
    foreach (PermissionRegistry::roleTemplates() as $role => $permissions) {
        $unknown = array_diff($permissions, PermissionRegistry::names());

        expect($unknown)->toBeEmpty("Role [{$role}] references unknown permissions: ".implode(', ', $unknown));
    }
});

it('gives every ops role access to the ops app and admin roles to the admin panel', function () {
    $templates = PermissionRegistry::roleTemplates();

    expect($templates[Role::Doctor->value])->toContain('ops.access')
        ->and($templates[Role::Cashier->value])->toContain('ops.access')
        ->and($templates[Role::BranchStaff->value])->toContain('ops.access')
        ->and($templates[Role::Accountant->value])->toContain('admin.access');
});
