<?php

use App\Enums\LocationType;
use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lot-tracked buckets: one row per product × branch × lot × expiry × location × cost layer.
        // Until Phase 4 links buckets to purchase lines, the cost layer is unit_cost + currency.
        // kit_id, customer_id (Phase 3), purchase_item_id, cost_fx_snapshot_id (Phase 4) are added later
        // and the unique index below is rebuilt to include them.
        Schema::create('stock_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products');
            $table->string('lot', 64)->nullable();
            $table->date('expiry_date')->nullable();
            $table->date('production_date')->nullable();
            $table->foreignId('branch_id')->constrained('branches');
            $table->string('location_type', 16)->default(LocationType::Shelf->value);
            $table->foreignId('branch_location_id')->nullable()->constrained('branch_locations');
            $table->integer('quantity')->default(0);
            $table->decimal('base_unit_cost', 18, 4)->default(0);
            $table->decimal('landed_unit_cost', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->char('cost_currency', 3);
            $table->foreign('cost_currency')->references('code')->on('currencies');
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers');
            $table->foreignId('last_expiry_level_id')->nullable()->constrained('expiry_levels')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->index('lot');
            $table->index('supplier_id');
        });

        Pg::enumCheck('stock_batches', 'location_type', LocationType::batchValues());
        Pg::check('stock_batches', 'stock_batches_quantity_not_negative', 'quantity >= 0');
        Pg::check('stock_batches', 'stock_batches_costs_not_negative', 'base_unit_cost >= 0 AND landed_unit_cost >= 0 AND unit_cost >= 0');
        Pg::check('stock_batches', 'stock_batches_true_cost', 'unit_cost = base_unit_cost + landed_unit_cost');
        Pg::trigramIndex('stock_batches', 'lot');

        Pg::uniqueNullsNotDistinct('stock_batches', 'stock_batches_bucket_unique', [
            'product_id', 'branch_id', 'lot', 'expiry_date', 'location_type', 'branch_location_id', 'unit_cost', 'cost_currency',
        ]);

        // FEFO: soonest expiry first among buckets that still hold something.
        DB::statement('CREATE INDEX stock_batches_fefo ON stock_batches (branch_id, product_id, expiry_date) WHERE quantity > 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_batches');
    }
};
