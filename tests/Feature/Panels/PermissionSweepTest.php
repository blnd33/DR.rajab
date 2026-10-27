<?php

use App\Models\User;
use App\Services\Reports\ReportRegistry;
use App\Services\Settings\SettingsRepository;
use Database\Seeders\DemoBranchSeeder;
use Database\Seeders\InventoryBaseSeeder;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Role;

/*
 * P5-10 permission sweep: a user who may only open the admin panel is refused on every
 * resource and report — nothing is reachable by URL that the menu would hide.
 */

beforeEach(function () {
    $this->seed([InventoryBaseSeeder::class, DemoBranchSeeder::class]);
    app(SettingsRepository::class)->set('security.require_admin_mfa', false);

    $role = Role::query()->create(['name' => 'panel_only', 'guard_name' => 'web']);
    $role->givePermissionTo(['admin.access', 'dashboard.view']);

    $this->user = User::factory()->create(['primary_branch_id' => branch('DHK')->id]);
    $this->user->assignRole($role);
    $this->user->branches()->sync([branch('DHK')->id]);
    $this->actingAs($this->user->fresh());
});

it('refuses every resource to a user without its permission', function () {
    $panel = Filament::getPanel('admin');
    $open = [];
    $checked = 0;

    foreach ($panel->getResources() as $resource) {
        if (! $resource::hasPage('index')) {
            continue;
        }

        $checked++;
        $status = $this->get($resource::getUrl('index', panel: 'admin'))->status();

        if ($status !== 403) {
            $open[$resource] = $status;
        }
    }

    expect($open)->toBe([])->and($checked)->toBeGreaterThan(40);
});

it('refuses every report, the health page and the print pages', function () {
    foreach (ReportRegistry::REPORTS as $class) {
        expect($this->get('/admin/reports/'.$class::key())->status())->toBe(403, $class::key())
            ->and($this->get(route('reports.print', ['report' => $class::key()]))->status())->toBe(403, 'print '.$class::key());
    }

    expect($this->get('/admin/system-health')->status())->toBe(403)
        ->and($this->get('/admin')->status())->toBe(200);
});
