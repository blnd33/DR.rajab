<?php

use App\Enums\Role;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\Customer;
use App\Models\Kit;
use App\Models\MoneyTransaction;
use App\Models\Patient;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Surgery;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAgentSeeder;
use Database\Seeders\DemoBranchSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\DemoClinicalSeeder;
use Database\Seeders\DemoMoneySeeder;
use Database\Seeders\DemoMovementSeeder;
use Database\Seeders\DemoUserSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

/*
 * "Clear the items list on the stock section" (client, 2026-11-22): the test site's items go with
 * all their stock and every document made of item lines; stations, people, kits and money stay.
 */

function seedEverything(): void
{
    test()->seed(DatabaseSeeder::BASE);
    test()->seed([
        DemoBranchSeeder::class,
        DemoUserSeeder::class,
        DemoCatalogSeeder::class,
        DemoMovementSeeder::class,
        DemoClinicalSeeder::class,
        DemoMoneySeeder::class,
        DemoAgentSeeder::class,
    ]);
}

it('refuses on a site that is not a test site', function () {
    seedEverything();
    $items = Product::query()->count();

    config(['orthotrack.test_site' => false]);
    $this->artisan('orthotrack:clear-items', ['--force' => true])->assertFailed();

    expect(Product::query()->count())->toBe($items)->and($items)->toBeGreaterThan(0);
});

it('removes nothing on a dry run or without the typed site name', function () {
    seedEverything();
    config(['orthotrack.test_site' => true, 'app.url' => 'https://test.example.com']);
    $items = Product::query()->count();

    $this->artisan('orthotrack:clear-items', ['--dry-run' => true])->assertSuccessful();
    $this->artisan('orthotrack:clear-items')
        ->expectsQuestion('This removes every item, all stock and the documents above from test.example.com (stations, users, customers, suppliers and money stay). Type test.example.com to confirm', 'app.example.com')
        ->assertFailed();

    expect(Product::query()->count())->toBe($items);
});

it('empties the items list with its stock and item documents, and keeps stations, people, kits and money', function () {
    seedEverything();
    config(['orthotrack.test_site' => true, 'app.url' => 'https://test.example.com']);

    $kept = fn () => [
        'branches' => Branch::query()->count(),
        'users' => User::query()->count(),
        'customers' => Customer::query()->count(),
        'suppliers' => Supplier::query()->count(),
        'patients' => Patient::query()->count(),
        'surgeries' => Surgery::query()->count(),
        'kits' => Kit::query()->count(),
        'money' => MoneyTransaction::query()->count(),
        'balances' => CashBox::query()->orderBy('id')->pluck('current_balance')->all(),
    ];
    $before = $kept();

    expect(Product::query()->count())->toBeGreaterThan(0)
        ->and(DB::table('stock_movements')->count())->toBeGreaterThan(0);

    $this->artisan('orthotrack:clear-items')
        ->expectsQuestion('This removes every item, all stock and the documents above from test.example.com (stations, users, customers, suppliers and money stay). Type test.example.com to confirm', 'test.example.com')
        ->assertSuccessful();

    foreach ([
        'products', 'product_barcodes', 'branch_product_settings', 'stock_units', 'stock_batches', 'stock_movements',
        'transfer_items', 'stock_requests', 'write_offs', 'customer_returns', 'supplier_returns', 'stock_counts',
        'purchases', 'purchase_items', 'purchase_receipts', 'landed_costs', 'sales_invoice_items', 'surgery_items',
        'stock_value_snapshots',
    ] as $table) {
        expect(DB::table($table)->count())->toBe(0, "{$table} still has rows");
    }

    expect(DB::table('kit_checkout_items')->whereNotNull('stock_unit_id')->orWhereNotNull('stock_batch_id')->orWhereNotNull('product_id')->count())->toBe(0)
        ->and(DB::table('kit_template_items')->whereNotNull('product_id')->count())->toBe(0)
        ->and($kept())->toBe($before);

    // The two ledgers it emptied from are append-only again afterwards.
    $triggers = DB::connection()->getDriverName() === 'pgsql'
        ? DB::table('pg_trigger')->where('tgname', 'like', '%_append_only')->pluck('tgname')->all()
        : DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'like', '%_append_only_delete')->pluck('name')->all();
    expect(implode(' ', $triggers))->toContain('stock_movements_append_only')->toContain('party_ledger_entries_append_only');

    // Running it again: nothing to do.
    $this->artisan('orthotrack:clear-items', ['--force' => true])
        ->expectsOutput('The Items list is already empty. Nothing to remove.')
        ->assertSuccessful();

    expect(DB::table('activity_log')->where('event', 'items_cleared')->count())->toBe(1);
});

it('leaves every admin list and record page working after the clear', function () {
    seedEverything();
    config(['orthotrack.test_site' => true]);
    app(SettingsRepository::class)->set('security.require_admin_mfa', false);

    $this->artisan('orthotrack:clear-items', ['--force' => true])->assertSuccessful();
    $this->actingAs(User::query()->whereHas('roles', fn ($q) => $q->where('name', Role::SuperAdmin->value))->firstOrFail());

    // A row left pointing at something removed shows up as a server error. (403 / 404 are fine
    // here: feature-flagged pages, and resources that share a model but show one kind each.)
    $broken = [];
    $opened = 0;

    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        if ($resource::hasPage('index')) {
            $status = $this->get($resource::getUrl('index', panel: 'admin'))->status();
            $status === 200 && $opened++;
            $status >= 500 && $broken[$resource.' index'] = $status;
        }

        if ($resource::hasPage('view')) {
            foreach ($resource::getModel()::query()->get() as $record) {
                $status = $this->get($resource::getUrl('view', ['record' => $record], panel: 'admin'))->status();
                $status === 200 && $opened++;
                $status >= 500 && $broken[$resource.' #'.$record->getKey()] = $status;
            }
        }
    }

    expect($broken)->toBe([])->and($opened)->toBeGreaterThan(60);
});
