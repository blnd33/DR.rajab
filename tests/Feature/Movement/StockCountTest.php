<?php

use App\Enums\CountLineResolution;
use App\Enums\CountLineResult;
use App\Enums\IssueType;
use App\Enums\MovementType;
use App\Enums\Role;
use App\Enums\StockCountScope;
use App\Enums\StockCountStatus;
use App\Enums\StockStatus;
use App\Enums\WriteOffStatus;
use App\Exceptions\Domain\StockRuleViolation;
use App\Livewire\Ops\Movement\CountShow;
use App\Models\Category;
use App\Models\Issue;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockCountScan;
use App\Models\StockMovement;
use App\Models\StockUnit;
use App\Models\WriteOff;
use App\Services\Movement\StockCountService;
use Database\Seeders\InventoryBaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

/*
 * Stock counts (Module 16): snapshot → scans (idempotent, offline queue) → submit → decide → close.
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    $this->counts = app(StockCountService::class);
    $this->dhk = branch('DHK');
    $this->bgw = branch('BGW');
    $this->admin = userWithRole(Role::SuperAdmin, [$this->dhk]);
    $this->staff = userWithRole(Role::BranchStaff, [$this->dhk]);
    $this->implant = Product::factory()->create(['sku' => 'KN-1']);
    $this->screw = Product::factory()->lot()->create(['sku' => 'SC-1']);

    stockIn($this->dhk, $this->admin, [
        ['product_id' => $this->implant->id, 'serial' => 'SN-1'],
        ['product_id' => $this->implant->id, 'serial' => 'SN-2'],
        ['product_id' => $this->screw->id, 'lot' => 'L1', 'quantity' => 20, 'unit_cost' => '2'],
    ]);
    stockIn($this->bgw, $this->admin, [['product_id' => $this->implant->id, 'serial' => 'SN-BGW']]);
});

it('snapshots the branch and counts each scan once', function () {
    $count = $this->counts->start($this->dhk, StockCountScope::Full, $this->staff);

    expect($count->number)->toStartWith('CNT')
        ->and($count->lines()->count())->toBe(3);

    $uuid = (string) Str::uuid();
    $this->counts->recordScan($count, $uuid, 'SN-1', $this->staff);
    $this->counts->recordScan($count, $uuid, 'SN-1', $this->staff);

    expect(StockCountScan::query()->count())->toBe(1)
        ->and($count->lines()->where('stock_unit_id', StockUnit::query()->where('serial', 'SN-1')->value('id'))->value('counted_qty'))->toBe(1);
});

it('syncs queued phone scans and ignores replays', function () {
    $count = $this->counts->start($this->dhk, StockCountScope::Full, $this->staff);
    $scans = [
        ['client_uuid' => (string) Str::uuid(), 'count_id' => $count->id, 'raw' => 'SN-1', 'scanned_at' => now()->subMinute()->toIso8601String()],
        ['client_uuid' => (string) Str::uuid(), 'count_id' => $count->id, 'raw' => 'SN-BGW'],
        ['client_uuid' => (string) Str::uuid(), 'count_id' => $count->id, 'raw' => 'NOTHING-LIKE-THIS'],
    ];

    $this->actingAs($this->staff);
    $first = $this->postJson('/app/sync/count-scans', ['scans' => $scans])->assertOk()->json();
    $this->postJson('/app/sync/count-scans', ['scans' => $scans])->assertOk();

    expect($first['accepted'])->toHaveCount(3)
        ->and(collect($first['results'])->pluck('result')->all())->toBe(['counted', CountLineResult::ExtraOtherBranch->value, 'unknown'])
        ->and(StockCountScan::query()->count())->toBe(3);
});

it('submits, opens an issue, and applies each decision', function () {
    $count = $this->counts->start($this->dhk, StockCountScope::Full, $this->staff);
    $this->counts->recordScan($count, (string) Str::uuid(), 'SN-1', $this->staff);
    $this->counts->recordScan($count, (string) Str::uuid(), 'SN-BGW', $this->staff);
    $lotLine = $count->lines()->whereNotNull('stock_batch_id')->firstOrFail();
    $this->counts->setCounted($lotLine, 17, $this->staff);

    $count = $this->counts->submit($count, $this->staff);

    expect($count->status)->toBe(StockCountStatus::Submitted)
        ->and($count->pendingDifferences())->toBe(3)
        ->and(Issue::query()->where('type', IssueType::CountDiscrepancy)->count())->toBe(1);

    $missing = $count->lines()->where('result', CountLineResult::Missing)->firstOrFail();
    $extra = $count->lines()->where('result', CountLineResult::ExtraOtherBranch)->firstOrFail();

    expect(fn () => $this->counts->resolveLine($extra, CountLineResolution::Adjusted, $this->admin))->toThrow(StockRuleViolation::class)
        ->and(fn () => $this->counts->close($count, $this->admin))->toThrow(StockRuleViolation::class);

    $this->counts->resolveLine($lotLine->fresh(), CountLineResolution::Adjusted, $this->admin);
    $this->counts->resolveLine($missing, CountLineResolution::WrittenOff, $this->admin);
    $this->counts->resolveLine($extra, CountLineResolution::Investigating, $this->admin, 'Ask BGW');
    $this->counts->close($count->fresh(), $this->admin);

    expect($count->fresh()->status)->toBe(StockCountStatus::Closed)
        ->and(StockBatch::query()->where('lot', 'L1')->value('quantity'))->toBe(17)
        ->and(StockMovement::query()->where('type', MovementType::CountAdjustment)->value('quantity'))->toBe(3)
        ->and(WriteOff::query()->where('status', WriteOffStatus::Pending)->count())->toBe(1)
        ->and(StockUnit::query()->where('serial', 'SN-2')->value('status'))->toBe(StockStatus::InStock);
});

it('stops scanning once submitted, and only reviewers decide', function () {
    $count = $this->counts->start($this->dhk, StockCountScope::Full, $this->staff);
    $count = $this->counts->submit($count, $this->staff);
    $line = $count->lines()->firstOrFail();

    expect(fn () => $this->counts->recordScan($count, (string) Str::uuid(), 'SN-1', $this->staff))->toThrow(StockRuleViolation::class)
        ->and(fn () => $this->counts->resolveLine($line, CountLineResolution::Ignored, $this->staff))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->counts->cancel($count, $this->staff))->toThrow(StockRuleViolation::class);
});

it('counts one category only', function () {
    $screws = Category::query()->create(['code' => 'SCR', 'name' => ['en' => 'Screws']]);
    $this->screw->update(['category_id' => $screws->id]);

    $count = $this->counts->start($this->dhk, StockCountScope::Category, $this->staff, $screws->id);

    expect($count->lines()->count())->toBe(1)
        ->and($count->lines()->value('product_id'))->toBe($this->screw->id)
        ->and(fn () => $this->counts->start($this->dhk, StockCountScope::Category, $this->staff))->toThrow(StockRuleViolation::class);
});

it('shows lot counts from the database after a synced scan', function () {
    $count = $this->counts->start($this->dhk, StockCountScope::Full, $this->staff);
    $lotLine = $count->lines()->whereNotNull('stock_batch_id')->firstOrFail();
    $this->actingAs($this->staff);

    $screen = Livewire\Livewire::test(CountShow::class, ['count' => $count])
        ->assertSet("lotCounts.{$lotLine->id}", 0);

    $this->counts->setCounted($lotLine, 5, $this->staff);

    $screen->call('$refresh')->assertSet("lotCounts.{$lotLine->id}", 5);
});
