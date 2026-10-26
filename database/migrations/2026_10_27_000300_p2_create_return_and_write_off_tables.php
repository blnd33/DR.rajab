<?php

use App\Enums\CustomerReturnStatus;
use App\Enums\ReturnCondition;
use App\Enums\ReturnReason;
use App\Enums\StockStatus;
use App\Enums\SupplierReturnReason;
use App\Enums\SupplierReturnStatus;
use App\Enums\WriteOffReason;
use App\Enums\WriteOffStatus;
use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Write-offs first: damaged customer returns point to one.
        Schema::create('write_offs', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('branch_id')->constrained('branches');
            $table->string('reason', 32);
            $table->string('status', 32)->default(WriteOffStatus::Pending->value);
            $table->text('note')->nullable();
            $table->nullableMorphs('source');
            $table->foreignId('reported_by')->constrained('users');
            $table->timestampTz('reported_at');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users');
            $table->timestampTz('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            // Filled in Phase 4 with stored exchange rates; per-item cost is kept now.
            $table->decimal('total_cost_usd', 18, 4)->nullable();
            $table->timestampsTz();

            $table->index(['branch_id', 'status']);
        });

        Pg::enumCheck('write_offs', 'reason', WriteOffReason::values());
        Pg::enumCheck('write_offs', 'status', WriteOffStatus::values());

        Schema::create('write_off_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('write_off_id')->constrained('write_offs')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('stock_unit_id')->nullable()->constrained('stock_units');
            $table->foreignId('stock_batch_id')->nullable()->constrained('stock_batches');
            $table->unsignedInteger('quantity')->default(1);
            $table->string('target_status', 32);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->char('cost_currency', 3);
            $table->timestampsTz();

            $table->index('write_off_id');
            $table->index('stock_unit_id');
        });

        Pg::enumCheck('write_off_items', 'target_status', [StockStatus::Damaged->value, StockStatus::Lost->value, StockStatus::ExpiredWrittenOff->value]);
        Pg::check('write_off_items', 'write_off_items_unit_or_batch', '(stock_unit_id IS NULL) <> (stock_batch_id IS NULL)');

        // patient_id (Phase 3) and origin links are added by the phases that create those tables.
        Schema::create('customer_returns', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('customer_id')->nullable()->constrained('customers');
            $table->string('status', 32)->default(CustomerReturnStatus::Draft->value);
            $table->foreignId('received_by')->nullable()->constrained('users');
            $table->timestampTz('received_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->text('void_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });

        Pg::enumCheck('customer_returns', 'status', CustomerReturnStatus::values());

        Schema::create('customer_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_return_id')->constrained('customer_returns')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('stock_unit_id')->nullable()->constrained('stock_units');
            $table->foreignId('stock_batch_id')->nullable()->constrained('stock_batches');
            $table->unsignedInteger('quantity')->default(1);
            $table->string('lot', 64)->nullable();
            $table->date('expiry_date')->nullable();
            $table->nullableMorphs('origin');
            $table->string('previous_status', 32)->nullable();
            $table->string('reason', 32);
            $table->string('condition', 16);
            $table->string('outcome', 16)->nullable();
            $table->foreignId('write_off_id')->nullable()->constrained('write_offs');
            $table->timestampsTz();
        });

        Pg::enumCheck('customer_return_items', 'reason', ReturnReason::values());
        Pg::enumCheck('customer_return_items', 'condition', ReturnCondition::values());
        Pg::enumCheck('customer_return_items', 'outcome', ['restocked', 'to_write_off'], nullable: true);

        // credit_note_id and fx_snapshot_id arrive with Phase 4.
        Schema::create('supplier_returns', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->foreignId('branch_id')->constrained('branches');
            $table->string('status', 32)->default(SupplierReturnStatus::Draft->value);
            $table->char('currency', 3)->nullable();
            $table->decimal('total_credit', 18, 4)->default(0);
            $table->foreignId('sent_by')->nullable()->constrained('users');
            $table->timestampTz('sent_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->text('void_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });

        Pg::enumCheck('supplier_returns', 'status', SupplierReturnStatus::values());

        Schema::create('supplier_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_return_id')->constrained('supplier_returns')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('stock_unit_id')->nullable()->constrained('stock_units');
            $table->foreignId('stock_batch_id')->nullable()->constrained('stock_batches');
            $table->unsignedInteger('quantity')->default(1);
            $table->string('reason', 32);
            $table->decimal('unit_credit', 18, 4)->default(0);
            $table->char('currency', 3)->nullable();
            $table->timestampsTz();
        });

        Pg::enumCheck('supplier_return_items', 'reason', SupplierReturnReason::values());
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_return_items');
        Schema::dropIfExists('supplier_returns');
        Schema::dropIfExists('customer_return_items');
        Schema::dropIfExists('customer_returns');
        Schema::dropIfExists('write_off_items');
        Schema::dropIfExists('write_offs');
    }
};
