<?php

use App\Enums\LocationType;
use App\Enums\StockStatus;
use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per physical serial-tracked unit. Only StockMovementService writes
        // status / branch / location, always together with a stock_movements row.
        // kit_id, customer_id (Phase 3), purchase_item_id, cost_fx_snapshot_id (Phase 4)
        // are added by those phases.
        Schema::create('stock_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products');
            $table->string('serial', 64)->unique();
            $table->string('lot', 64)->nullable();
            $table->date('expiry_date')->nullable();
            $table->date('production_date')->nullable();
            $table->foreignId('branch_id')->constrained('branches');
            $table->string('status', 32)->default(StockStatus::InStock->value);
            $table->string('location_type', 16)->default(LocationType::Shelf->value);
            $table->foreignId('branch_location_id')->nullable()->constrained('branch_locations');
            $table->decimal('base_unit_cost', 18, 4)->default(0);
            $table->decimal('landed_unit_cost', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->char('cost_currency', 3);
            $table->foreign('cost_currency')->references('code')->on('currencies');
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers');
            $table->timestampTz('received_at');
            $table->foreignId('last_expiry_level_id')->nullable()->constrained('expiry_levels')->nullOnDelete();
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->index(['product_id', 'branch_id', 'status']);
            $table->index(['branch_id', 'status', 'expiry_date']);
            $table->index('status');
            $table->index('lot');
            $table->index('expiry_date');
            $table->index('supplier_id');
        });

        Pg::enumCheck('stock_units', 'status', StockStatus::values());
        Pg::enumCheck('stock_units', 'location_type', LocationType::values());
        Pg::check('stock_units', 'stock_units_costs_not_negative', 'base_unit_cost >= 0 AND landed_unit_cost >= 0 AND unit_cost >= 0');
        Pg::check('stock_units', 'stock_units_true_cost', 'unit_cost = base_unit_cost + landed_unit_cost');
        Pg::trigramIndex('stock_units', 'serial');
        Pg::trigramIndex('stock_units', 'lot');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_units');
    }
};
