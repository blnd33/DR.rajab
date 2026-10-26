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
     * Implants inside a kit stay stock of the branch with location "inside kit X";
     * items out at a hospital or used in surgery remember which hospital (customer).
     */
    public function up(): void
    {
        StockViews::drop();

        Schema::table('stock_units', function (Blueprint $table) {
            $table->foreignId('kit_id')->nullable()->constrained('kits');
            $table->foreignId('customer_id')->nullable()->constrained('customers');
            $table->index('kit_id');
        });

        Schema::table('stock_batches', function (Blueprint $table) {
            $table->foreignId('kit_id')->nullable()->constrained('kits');
            $table->foreignId('customer_id')->nullable()->constrained('customers');
            $table->index('kit_id');
        });

        // Ledger columns from DATABASE.md. SQLite would rebuild the append-only table (and lose
        // its triggers) to add foreign keys, so the preview gets plain columns.
        Schema::table('stock_movements', function (Blueprint $table) {
            foreach (['from_kit_id' => 'kits', 'to_kit_id' => 'kits', 'customer_id' => 'customers'] as $column => $on) {
                Pg::isPostgres()
                    ? $table->foreignId($column)->nullable()->constrained($on)
                    : $table->unsignedBigInteger($column)->nullable();
            }
        });

        // A kit's lot quantities are their own bucket: the kit is part of the key.
        DB::statement('DROP INDEX IF EXISTS stock_batches_bucket_unique');
        Pg::uniqueNullsNotDistinct('stock_batches', 'stock_batches_bucket_unique', [
            'product_id', 'branch_id', 'lot', 'expiry_date', 'location_type', 'branch_location_id', 'kit_id', 'unit_cost', 'cost_currency',
        ]);

        Pg::check('stock_units', 'stock_units_in_kit_has_kit', "status <> 'in_kit' OR kit_id IS NOT NULL");
        StockViews::create();

        Pg::check('stock_batches', 'stock_batches_kit_location', "(location_type = 'kit') = (kit_id IS NOT NULL) OR (location_type = 'hospital' AND kit_id IS NOT NULL)");
    }

    public function down(): void
    {
        StockViews::drop();
        DB::statement('DROP INDEX IF EXISTS stock_batches_bucket_unique');

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn(['from_kit_id', 'to_kit_id', 'customer_id']);
        });

        Schema::table('stock_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kit_id');
            $table->dropConstrainedForeignId('customer_id');
        });

        Schema::table('stock_units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kit_id');
            $table->dropConstrainedForeignId('customer_id');
        });

        Pg::uniqueNullsNotDistinct('stock_batches', 'stock_batches_bucket_unique', [
            'product_id', 'branch_id', 'lot', 'expiry_date', 'location_type', 'branch_location_id', 'unit_cost', 'cost_currency',
        ]);
        StockViews::create();
    }
};
