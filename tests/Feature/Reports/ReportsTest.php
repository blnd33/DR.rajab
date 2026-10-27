<?php

use App\Enums\Role;
use App\Models\StockValueSnapshot;
use App\Services\Reports\ExpensesReport;
use App\Services\Reports\ReportContext;
use App\Services\Reports\ReportRegistry;
use App\Services\Reports\SalesProfitReport;
use App\Services\Reports\StockValueReport;
use App\Services\Reports\StockValueSnapshotter;
use App\Services\Reports\SupplierAgingReport;
use App\Services\Settings\SettingsRepository;
use Database\Seeders\DemoBranchSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\DemoClinicalSeeder;
use Database\Seeders\DemoMoneySeeder;
use Database\Seeders\DemoMovementSeeder;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\InventoryBaseSeeder;

/*
 * Reports (P5-1/2): every report opens, prints and exports in every language; totals match
 * the demo data worked out by hand; profit and branches stay with the people allowed.
 */

beforeEach(function () {
    $this->seed([InventoryBaseSeeder::class, ExpenseCategorySeeder::class, DemoBranchSeeder::class]);
    app(SettingsRepository::class)->set('security.require_admin_mfa', false);
    $this->admin = userWithRole(Role::SuperAdmin, [branch('DHK')], ['username' => 'superadmin']);
    $this->actingAs($this->admin);
    $this->seed([DemoCatalogSeeder::class, DemoMovementSeeder::class, DemoClinicalSeeder::class, DemoMoneySeeder::class]);
});

function reportContext($test, string $class, array $input = []): ReportContext
{
    return ReportContext::make(app($class), $test->admin, $input + ['from' => now()->subYear()->toDateString()]);
}

it('opens, prints and exports every report in every language', function (string $locale) {
    $this->admin->forceFill(['locale' => $locale])->save();
    $this->actingAs($this->admin->fresh());

    foreach (ReportRegistry::REPORTS as $class) {
        $key = $class::key();
        $report = app($class);

        expect($this->get("/admin/reports/{$key}")->status())->toBe(200, "{$key} in {$locale}");

        foreach (array_keys($report->groups()) ?: [null] as $group) {
            $print = $this->get(route('reports.print', array_filter(['report' => $key, 'from' => now()->subYear()->toDateString(), 'group' => $group])));
            expect($print->status())->toBe(200, "print {$key} {$group} in {$locale}");
        }

        $sheet = ReportRegistry::sheet($report, ReportContext::make($report, $this->admin->fresh(), ['from' => now()->subYear()->toDateString()]));
        expect($sheet->headings())->not->toBeEmpty();
    }
})->with(['en', 'ar', 'ckb']);

it('ages what we owe suppliers with each invoice\'s own rate', function () {
    $context = reportContext($this, SupplierAgingReport::class);
    $report = app(SupplierAgingReport::class);
    $rows = collect($report->rows($context))->keyBy('code');
    $totals = $report->totals($context, $rows->values()->all());

    // NovaJoint: EUR 2,035.19 open × 1.08 = USD 2,198.00, due in 40 days.
    expect($rows['NVJ']['not_due'])->toBe('2198.00')
        // BoneTech: 2,640 (60 days old on 45-day terms → 15 days late) + 7,040 not due yet.
        ->and($rows['BTS']['d30'])->toBe('2640.00')
        ->and($rows['BTS']['not_due'])->toBe('7040.00')
        ->and($totals['open'])->toBe('11878.00');
});

it('counts expenses for each branch\'s share and converts IQD with its own rate', function () {
    $context = reportContext($this, ExpensesReport::class, ['group' => 'branch']);
    $report = app(ExpensesReport::class);
    $rows = collect($report->rows($context))->keyBy('key');

    // Duhok: rent 850 + generator 175,000 IQD (÷ 1,460 = 119.86) + 60 % of the 600 licence.
    expect($rows['b'.branch('DHK')->id]['amount'])->toBe('1329.86')
        // Erbil: 40 % of the licence; the 1,400 repair still waits for approval.
        ->and($rows['b'.branch('EBL')->id]['amount'])->toBe('240.00')
        ->and($report->totals($context, $rows->values()->all())['amount'])->toBe('1569.86');
});

it('shows revenue to everyone with reports and cost and profit only with reports.profit', function () {
    $report = app(SalesProfitReport::class);
    $context = reportContext($this, SalesProfitReport::class);
    $row = $report->rows($context)[0];

    expect($row['revenue'])->toBe('140.00')
        ->and((float) $row['profit'])->toBeLessThan(140.0)
        ->and(collect($report->visibleColumns($context))->pluck('name'))->toContain('profit');

    $viewer = userWithRole(Role::Accountant, [branch('DHK')]);
    $viewer->revokePermissionTo('reports.profit');
    $viewer->roles->each(fn ($role) => $role->revokePermissionTo('reports.profit'));
    $limited = ReportContext::make($report, $viewer->fresh(), []);

    expect(collect($report->visibleColumns($limited))->pluck('name'))->not->toContain('profit')->not->toContain('cost');
});

it('keeps other branches out of a branch user\'s reports', function () {
    $accountant = userWithRole(Role::Accountant, [branch('EBL')]);
    $context = ReportContext::make(app(ExpensesReport::class), $accountant, ['from' => now()->subYear()->toDateString(), 'group' => 'branch', 'branch' => branch('DHK')->id]);

    expect($context->branchIds)->toBe([0])
        ->and(app(ExpensesReport::class)->rows($context))->toBe([]);
});

it('snapshots stock value every night and shows the history', function () {
    $written = app(StockValueSnapshotter::class)->snapshot();
    app(StockValueSnapshotter::class)->snapshot();

    expect($written)->toBeGreaterThan(0)
        ->and(StockValueSnapshot::query()->count())->toBe($written);

    $report = app(StockValueReport::class);
    $live = $report->totals($context = reportContext($this, StockValueReport::class, ['group' => 'branch']), $report->rows($context));
    $history = $report->rows(reportContext($this, StockValueReport::class, ['group' => 'history']));

    expect(collect($history)->sum(fn ($r) => (float) $r['value']))->toEqualWithDelta((float) $live['value'], 0.05);
});
