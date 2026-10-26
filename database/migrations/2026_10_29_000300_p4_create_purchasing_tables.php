<?php

use App\Enums\AllocationMethod;
use App\Enums\CreditNoteDirection;
use App\Enums\CreditNoteStatus;
use App\Enums\LandedCostStatus;
use App\Enums\LandedCostType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use App\Enums\ReceiptMethod;
use App\Enums\ReceivingStatus;
use App\Enums\SupplierPaymentStatus;
use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->string('supplier_invoice_number', 64);
            $table->foreignId('branch_id')->constrained('branches'); // receiving branch
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->char('currency', 3);
            $table->foreign('currency')->references('code')->on('currencies');
            $table->foreignId('fx_snapshot_id')->constrained('fx_snapshots');
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('discount', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);
            $table->decimal('paid_amount', 18, 4)->default(0); // cached
            $table->string('status', 16)->default(PurchaseStatus::Draft->value);
            $table->string('receiving_status', 32)->default(ReceivingStatus::NotReceived->value);
            $table->string('payment_status', 16)->default(PaymentStatus::Unpaid->value);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('confirmed_by')->nullable()->constrained('users');
            $table->timestampTz('confirmed_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->timestampTz('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['supplier_id', 'supplier_invoice_number']);
            $table->index(['payment_status', 'due_date']);
            $table->index('branch_id');
        });

        Pg::enumCheck('purchases', 'status', PurchaseStatus::values());
        Pg::enumCheck('purchases', 'receiving_status', ReceivingStatus::values());
        Pg::enumCheck('purchases', 'payment_status', PaymentStatus::values());
        Pg::check('purchases', 'purchases_amounts', 'subtotal >= 0 AND discount >= 0 AND total >= 0 AND paid_amount >= 0');

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained('purchases')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('quantity_received')->default(0);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('line_total', 18, 4);
            $table->string('expected_lot', 64)->nullable();
            $table->date('expected_expiry')->nullable();
            $table->decimal('landed_cost_per_unit', 18, 4)->default(0);
            $table->decimal('true_unit_cost', 18, 4);
            $table->timestampsTz();

            $table->index('purchase_id');
        });

        Pg::check('purchase_items', 'purchase_items_qty', 'quantity > 0 AND quantity_received <= quantity AND unit_price >= 0');

        Schema::create('purchase_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('purchase_id')->constrained('purchases');
            $table->foreignId('branch_id')->constrained('branches');
            $table->string('method', 16)->default(ReceiptMethod::Manual->value);
            $table->foreignId('received_by')->constrained('users');
            $table->timestampTz('received_at');
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });

        Pg::enumCheck('purchase_receipts', 'method', ReceiptMethod::values());

        Schema::create('purchase_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_receipt_id')->constrained('purchase_receipts')->cascadeOnDelete();
            $table->foreignId('purchase_item_id')->constrained('purchase_items');
            $table->foreignId('stock_unit_id')->nullable()->constrained('stock_units');
            $table->foreignId('stock_batch_id')->nullable()->constrained('stock_batches');
            $table->string('serial', 64)->nullable();
            $table->string('lot', 64)->nullable();
            $table->date('expiry_date')->nullable();
            $table->unsignedInteger('quantity');
            $table->timestampsTz();
        });

        Schema::create('landed_costs', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('purchase_id')->constrained('purchases');
            $table->string('type', 16);
            $table->foreignId('payee_supplier_id')->nullable()->constrained('suppliers');
            $table->decimal('amount', 18, 4);
            $table->char('currency', 3);
            $table->foreign('currency')->references('code')->on('currencies');
            $table->foreignId('fx_snapshot_id')->constrained('fx_snapshots');
            $table->string('allocation_method', 16)->default(AllocationMethod::Value->value);
            $table->string('status', 16)->default(LandedCostStatus::Allocated->value);
            $table->decimal('cogs_amount', 18, 4)->default(0); // part already used / sold (purchase currency)
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestampTz('allocated_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->timestampTz('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestampsTz();
        });

        Pg::enumCheck('landed_costs', 'type', LandedCostType::values());
        Pg::enumCheck('landed_costs', 'allocation_method', AllocationMethod::values());
        Pg::enumCheck('landed_costs', 'status', LandedCostStatus::values());
        Pg::check('landed_costs', 'landed_costs_positive', 'amount > 0');

        Schema::create('landed_cost_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landed_cost_id')->constrained('landed_costs')->cascadeOnDelete();
            $table->foreignId('purchase_item_id')->constrained('purchase_items');
            $table->decimal('amount', 18, 4); // in the purchase currency
            $table->decimal('per_unit_amount', 18, 4);
            $table->decimal('rate_used', 18, 8);
            $table->unsignedInteger('quantity_on_hand')->default(0);
            $table->unsignedInteger('quantity_used')->default(0);
            $table->timestampsTz();
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->foreignId('cash_box_id')->constrained('cash_boxes');
            $table->decimal('amount', 18, 4);
            $table->char('currency', 3); // = box currency
            $table->foreignId('fx_snapshot_id')->constrained('fx_snapshots');
            $table->decimal('amount_usd', 18, 4);
            $table->string('method', 16)->default(PaymentMethod::BankWire->value);
            $table->string('reference', 120)->nullable();
            $table->date('paid_at');
            $table->string('status', 32)->default(SupplierPaymentStatus::Posted->value);
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('money_transaction_id')->nullable()->constrained('money_transactions');
            $table->decimal('fx_gain_loss_usd', 18, 4)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->timestampTz('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestampsTz();

            $table->index(['supplier_id', 'status']);
        });

        Pg::enumCheck('supplier_payments', 'method', PaymentMethod::values());
        Pg::enumCheck('supplier_payments', 'status', SupplierPaymentStatus::values());
        Pg::check('supplier_payments', 'supplier_payments_positive', 'amount > 0');

        Schema::create('supplier_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_payment_id')->constrained('supplier_payments')->cascadeOnDelete();
            $table->foreignId('purchase_id')->constrained('purchases');
            $table->decimal('amount_in_payment_currency', 18, 4);
            $table->decimal('amount_in_purchase_currency', 18, 4);
            $table->decimal('rate_used', 18, 8); // 1 payment currency = rate purchase currency
            $table->decimal('fx_gain_loss_usd', 18, 4)->default(0); // realized vs invoice snapshot
            $table->timestampsTz();
        });

        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->morphs('party');
            $table->string('direction', 16);
            $table->nullableMorphs('source');
            $table->char('currency', 3);
            $table->foreign('currency')->references('code')->on('currencies');
            $table->foreignId('fx_snapshot_id')->constrained('fx_snapshots');
            $table->decimal('amount', 18, 4);
            $table->string('status', 16)->default(CreditNoteStatus::Open->value);
            $table->string('reference', 120)->nullable(); // supplier's credit note number
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->timestampTz('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestampsTz();
        });

        Pg::enumCheck('credit_notes', 'direction', CreditNoteDirection::values());
        Pg::enumCheck('credit_notes', 'status', CreditNoteStatus::values());
        Pg::check('credit_notes', 'credit_notes_positive', 'amount > 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_notes');
        Schema::dropIfExists('supplier_payment_allocations');
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('landed_cost_allocations');
        Schema::dropIfExists('landed_costs');
        Schema::dropIfExists('purchase_receipt_items');
        Schema::dropIfExists('purchase_receipts');
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
    }
};
