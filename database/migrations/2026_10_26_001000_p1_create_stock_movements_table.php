<?php

use App\Enums\MovementType;
use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // THE stock ledger. Append-only (trigger below): a mistake is fixed by a new row.
        // from_kit_id / to_kit_id, customer_id (Phase 3) and fx_snapshot_id (Phase 4) are added later.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->uuid('movement_group');
            $table->string('type', 32);
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('stock_unit_id')->nullable()->constrained('stock_units');
            $table->foreignId('from_batch_id')->nullable()->constrained('stock_batches');
            $table->foreignId('to_batch_id')->nullable()->constrained('stock_batches');
            $table->integer('quantity');
            $table->foreignId('from_branch_id')->nullable()->constrained('branches');
            $table->foreignId('to_branch_id')->nullable()->constrained('branches');
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->string('from_location_type', 16)->nullable();
            $table->string('to_location_type', 16)->nullable();
            $table->decimal('unit_cost', 18, 4)->nullable();
            $table->char('cost_currency', 3)->nullable();
            $table->foreign('cost_currency')->references('code')->on('currencies');
            $table->nullableMorphs('reference');
            $table->text('reason')->nullable();
            // Corrections keep the old → new values (lot, expiry, cost…).
            $table->jsonb('details')->nullable();
            $table->boolean('is_override')->default(false);
            $table->text('override_reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['stock_unit_id', 'occurred_at']);
            $table->index('from_batch_id');
            $table->index('to_batch_id');
            $table->index(['product_id', 'occurred_at']);
            $table->index('from_branch_id');
            $table->index('to_branch_id');
            $table->index('movement_group');
            $table->index(['type', 'occurred_at']);
        });

        Pg::enumCheck('stock_movements', 'type', MovementType::values());
        Pg::check('stock_movements', 'stock_movements_quantity_positive', 'quantity > 0');
        Pg::check('stock_movements', 'stock_movements_serial_qty_one', 'stock_unit_id IS NULL OR quantity = 1');
        Pg::check('stock_movements', 'stock_movements_unit_or_batch', 'stock_unit_id IS NOT NULL OR from_batch_id IS NOT NULL OR to_batch_id IS NOT NULL');
        Pg::check('stock_movements', 'stock_movements_override_reason', 'NOT is_override OR override_reason IS NOT NULL');

        Pg::appendOnly('stock_movements');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
