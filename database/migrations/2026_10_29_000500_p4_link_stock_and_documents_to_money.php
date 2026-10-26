<?php

use App\Support\Database\Pg;
use App\Support\Database\StockViews;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stock remembers the purchase line it came from (its own cost layer) and the rate set
     * of its cost; older documents get their snapshot / credit-note columns (P4-7, P4-16).
     */
    public function up(): void
    {
        StockViews::drop();

        Schema::table('stock_units', function (Blueprint $table) {
            $table->foreignId('purchase_item_id')->nullable()->constrained('purchase_items');
            $table->foreignId('cost_fx_snapshot_id')->nullable()->constrained('fx_snapshots');
        });

        Schema::table('stock_batches', function (Blueprint $table) {
            $table->foreignId('purchase_item_id')->nullable()->constrained('purchase_items');
            $table->foreignId('cost_fx_snapshot_id')->nullable()->constrained('fx_snapshots');
        });

        // Each purchase line keeps its own bucket, so landed costs reach only its quantity.
        DB::statement('DROP INDEX IF EXISTS stock_batches_bucket_unique');
        Pg::uniqueNullsNotDistinct('stock_batches', 'stock_batches_bucket_unique', [
            'product_id', 'branch_id', 'lot', 'expiry_date', 'location_type', 'branch_location_id', 'kit_id', 'purchase_item_id', 'unit_cost', 'cost_currency',
        ]);

        StockViews::create();

        // The append-only ledger: plain column on the SQLite preview (no table rebuild).
        Schema::table('stock_movements', function (Blueprint $table) {
            Pg::isPostgres()
                ? $table->foreignId('fx_snapshot_id')->nullable()->constrained('fx_snapshots')
                : $table->unsignedBigInteger('fx_snapshot_id')->nullable();
        });

        Schema::table('write_offs', function (Blueprint $table) {
            $table->foreignId('fx_snapshot_id')->nullable()->constrained('fx_snapshots');
        });

        Schema::table('supplier_returns', function (Blueprint $table) {
            $table->foreignId('fx_snapshot_id')->nullable()->constrained('fx_snapshots');
            $table->foreignId('credit_note_id')->nullable()->constrained('credit_notes');
        });

        Schema::table('customer_return_items', function (Blueprint $table) {
            $table->foreignId('credit_note_id')->nullable()->constrained('credit_notes');
        });

        Schema::table('surgeries', function (Blueprint $table) {
            $table->foreignId('fee_fx_snapshot_id')->nullable()->constrained('fx_snapshots');
            $table->foreignId('sales_invoice_id')->nullable()->constrained('sales_invoices');
        });
    }

    public function down(): void
    {
        Schema::table('surgeries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fee_fx_snapshot_id');
            $table->dropConstrainedForeignId('sales_invoice_id');
        });

        Schema::table('customer_return_items', fn (Blueprint $table) => $table->dropConstrainedForeignId('credit_note_id'));

        Schema::table('supplier_returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fx_snapshot_id');
            $table->dropConstrainedForeignId('credit_note_id');
        });

        Schema::table('write_offs', fn (Blueprint $table) => $table->dropConstrainedForeignId('fx_snapshot_id'));
        Schema::table('stock_movements', fn (Blueprint $table) => $table->dropColumn('fx_snapshot_id'));

        StockViews::drop();
        DB::statement('DROP INDEX IF EXISTS stock_batches_bucket_unique');

        foreach (['stock_batches', 'stock_units'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('purchase_item_id');
                $table->dropConstrainedForeignId('cost_fx_snapshot_id');
            });
        }

        Pg::uniqueNullsNotDistinct('stock_batches', 'stock_batches_bucket_unique', [
            'product_id', 'branch_id', 'lot', 'expiry_date', 'location_type', 'branch_location_id', 'kit_id', 'unit_cost', 'cost_currency',
        ]);
        StockViews::create();
    }
};
