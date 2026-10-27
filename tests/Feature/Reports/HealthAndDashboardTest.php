<?php

use App\Enums\Role;
use App\Filament\Admin\Widgets\Charts\SalesTrendChart;
use App\Filament\Admin\Widgets\Charts\StockValueTrendChart;
use App\Filament\Admin\Widgets\Charts\SurgeriesTrendChart;
use App\Filament\Admin\Widgets\SystemAlerts;
use App\Filament\Admin\Widgets\TopImplants;
use App\Models\SchedulerHeartbeat;
use App\Services\Health\Heartbeat;
use App\Services\Health\SystemHealth;
use App\Services\Reports\StockValueSnapshotter;
use App\Services\Settings\SettingsRepository;
use Database\Seeders\DemoBranchSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\DemoClinicalSeeder;
use Database\Seeders\DemoMoneySeeder;
use Database\Seeders\DemoMovementSeeder;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\InventoryBaseSeeder;

/*
 * System health (P5-6) and the full dashboard (P5-5).
 */

beforeEach(function () {
    $this->seed([InventoryBaseSeeder::class, ExpenseCategorySeeder::class, DemoBranchSeeder::class]);
    app(SettingsRepository::class)->set('security.require_admin_mfa', false);
    $this->admin = userWithRole(Role::SuperAdmin, [branch('DHK')], ['username' => 'superadmin']);
    $this->actingAs($this->admin);
});

it('tracks scheduled jobs and flags a late or failed one', function () {
    Heartbeat::started('stock:alerts');
    Heartbeat::finished('stock:alerts', 'ok');
    Heartbeat::finished('money:daily', 'failed');
    SchedulerHeartbeat::query()->updateOrCreate(['name' => 'reports:snapshot'], ['last_finished_at' => now()->subDays(3), 'last_status' => 'ok']);

    $checks = collect(app(SystemHealth::class)->checks())->keyBy('key');

    expect($checks['job-stock:alerts']['status'])->toBe(SystemHealth::OK)
        ->and($checks['job-money:daily']['status'])->toBe(SystemHealth::CRITICAL)
        ->and($checks['job-reports:snapshot']['status'])->toBe(SystemHealth::CRITICAL)
        ->and($checks['job-backup:run']['status'])->toBe(SystemHealth::WARNING)
        ->and($checks['rate-USDIQD']['status'])->toBe(SystemHealth::CRITICAL);
});

it('shows the health page to the super admin only', function (string $locale) {
    $this->admin->forceFill(['locale' => $locale])->save();
    $this->actingAs($this->admin->fresh());

    $this->get('/admin/system-health')->assertOk()->assertSee('PHP');

    $this->actingAs(userWithRole(Role::Accountant, [branch('DHK')]));
    $this->get('/admin/system-health')->assertForbidden();
})->with(['en', 'ar', 'ckb']);

it('draws the full dashboard with charts in every language', function (string $locale) {
    $this->seed([DemoCatalogSeeder::class, DemoMovementSeeder::class, DemoClinicalSeeder::class, DemoMoneySeeder::class]);
    app(StockValueSnapshotter::class)->snapshot();
    $this->admin->forceFill(['locale' => $locale])->save();
    $this->actingAs($this->admin->fresh());

    foreach ([
        SalesTrendChart::class,
        SurgeriesTrendChart::class,
        StockValueTrendChart::class,
        TopImplants::class,
        SystemAlerts::class,
    ] as $widget) {
        Livewire\Livewire::test($widget)->assertOk();
    }

    $this->get('/admin')->assertOk();
})->with(['en', 'ar', 'ckb']);
