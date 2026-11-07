<?php

use App\Support\Database\Pg;
use App\Support\Database\StockViews;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot items may carry the box's serial number (SN) when the label has one: the box is then
 * its own bucket of 1, found again by scanning the SN. A box with only a LOT works as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        StockViews::drop();
        DB::statement('DROP INDEX IF EXISTS stock_batches_bucket_unique');

        Schema::table('stock_batches', function (Blueprint $table) {
            $table->string('serial', 64)->nullable()->after('lot');
            $table->index('serial');
        });

        Pg::uniqueNullsNotDistinct('stock_batches', 'stock_batches_bucket_unique', [
            'product_id', 'branch_id', 'lot', 'serial', 'expiry_date', 'location_type', 'branch_location_id', 'kit_id', 'purchase_item_id', 'unit_cost', 'cost_currency',
        ]);
        Pg::check('stock_batches', 'stock_batches_serial_qty_one', 'serial IS NULL OR quantity <= 1');
        StockViews::create();
    }

    public function down(): void
    {
        StockViews::drop();
        DB::statement('DROP INDEX IF EXISTS stock_batches_bucket_unique');

        if (Pg::isPostgres()) {
            DB::statement('ALTER TABLE stock_batches DROP CONSTRAINT IF EXISTS stock_batches_serial_qty_one');
        }

        Schema::table('stock_batches', function (Blueprint $table) {
            $table->dropIndex(['serial']);
            $table->dropColumn('serial');
        });

        Pg::uniqueNullsNotDistinct('stock_batches', 'stock_batches_bucket_unique', [
            'product_id', 'branch_id', 'lot', 'expiry_date', 'location_type', 'branch_location_id', 'kit_id', 'purchase_item_id', 'unit_cost', 'cost_currency',
        ]);
        StockViews::create();
    }
};
