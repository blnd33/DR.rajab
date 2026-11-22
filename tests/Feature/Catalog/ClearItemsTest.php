<?php

use App\Models\CashBox;
use App\Services\Catalog\ItemsResetService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;

/*
 * orthotrack:clear-items (test site): the Items list and everything made of items are removed,
 * everything else stays, box balances follow the money that is left, the ledgers stay guarded.
 */

beforeEach(function () {
    config(['orthotrack.test_site' => false]);
    $this->seed(DatabaseSeeder::class);
});

it('refuses on a site that is not a test site', function () {
    $this->artisan('orthotrack:clear-items', ['--force' => true])->assertFailed();

    expect(DB::table('products')->count())->toBeGreaterThan(0);
});

it('empties the items, their stock and documents, and keeps the rest', function () {
    config(['orthotrack.test_site' => true]);
    $kept = fn () => [
        'users' => DB::table('users')->count(), 'branches' => DB::table('branches')->count(),
        'customers' => DB::table('customers')->count(), 'suppliers' => DB::table('suppliers')->count(),
        'patients' => DB::table('patients')->count(), 'categories' => DB::table('categories')->count(),
        'kits' => DB::table('kits')->count(), 'instruments' => DB::table('instruments')->count(),
        'expenses' => DB::table('expenses')->count(), 'cash_boxes' => DB::table('cash_boxes')->count(),
    ];
    $before = $kept();

    $this->artisan('orthotrack:clear-items', ['--dry-run' => true])->assertSuccessful();
    expect(DB::table('products')->count())->toBeGreaterThan(0);

    $this->artisan('orthotrack:clear-items', ['--force' => true])->assertSuccessful();

    foreach (['products', 'stock_units', 'stock_batches', 'stock_movements', 'purchases', 'sales_invoices', 'surgeries', 'transfers', 'kit_checkouts', 'write_offs', 'stock_counts'] as $table) {
        expect(DB::table($table)->count())->toBe(0, $table);
    }

    expect($kept())->toBe($before)
        ->and(DB::table('kits')->where('status', '<>', 'available')->count())->toBe(0);

    foreach (CashBox::all() as $box) {
        $sum = (float) DB::table('money_transactions')->where('cash_box_id', $box->id)->sum('amount');
        expect((float) $box->current_balance)->toBe($sum, $box->name);
    }

    expect(app(ItemsResetService::class)->counts())->toBe([]);

    // The ledgers are guarded again (last: the refused delete ends the test's transaction).
    expect(fn () => DB::table('money_transactions')->limit(1)->delete())->toThrow(Exception::class);
});
