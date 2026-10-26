<?php

use App\Enums\CommissionBasis;
use App\Enums\CommissionCalc;
use App\Enums\CommissionStatus;
use App\Enums\CustomerPaymentStatus;
use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentTerms;
use App\Enums\SalesInvoiceStatus;
use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('customer_id')->nullable()->constrained('customers'); // NULL = walk-in
            $table->string('walk_in_name', 150)->nullable();
            $table->foreignId('patient_id')->nullable()->constrained('patients');
            $table->foreignId('surgery_id')->nullable()->constrained('surgeries'); // billing hook
            $table->char('currency', 3);
            $table->foreign('currency')->references('code')->on('currencies');
            $table->foreignId('fx_snapshot_id')->constrained('fx_snapshots');
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('discount', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);
            $table->decimal('paid_amount', 18, 4)->default(0);
            $table->string('payment_terms', 16)->default(PaymentTerms::Cash->value);
            $table->date('due_date')->nullable();
            $table->string('status', 16)->default(SalesInvoiceStatus::Draft->value);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('issued_by')->nullable()->constrained('users');
            $table->timestampTz('issued_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->timestampTz('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestampsTz();

            $table->index(['branch_id', 'issued_at']);
            $table->index(['customer_id', 'status']);
        });

        Pg::enumCheck('sales_invoices', 'status', SalesInvoiceStatus::values());
        Pg::enumCheck('sales_invoices', 'payment_terms', PaymentTerms::values());

        Schema::create('sales_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_invoice_id')->constrained('sales_invoices')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('stock_unit_id')->nullable()->constrained('stock_units');
            $table->foreignId('stock_batch_id')->nullable()->constrained('stock_batches');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 18, 4);
            $table->decimal('discount', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4);
            $table->decimal('unit_cost', 18, 4)->nullable(); // for profit
            $table->char('cost_currency', 3)->nullable();
            $table->foreignId('cost_fx_snapshot_id')->nullable()->constrained('fx_snapshots');
            $table->unsignedInteger('returned_qty')->default(0);
            $table->text('override_reason')->nullable();
            $table->timestampsTz();

            $table->index('sales_invoice_id');
        });

        Pg::check('sales_invoice_items', 'sales_invoice_items_qty', '(stock_unit_id IS NULL OR quantity = 1) AND quantity > 0');

        Schema::create('customer_payments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('customer_id')->nullable()->constrained('customers');
            $table->foreignId('cash_box_id')->constrained('cash_boxes');
            $table->decimal('amount', 18, 4);
            $table->char('currency', 3);
            $table->foreignId('fx_snapshot_id')->constrained('fx_snapshots');
            $table->decimal('applied_rate', 18, 8)->nullable();
            $table->string('method', 16)->default(PaymentMethod::Cash->value);
            $table->string('reference', 120)->nullable();
            $table->foreignId('received_by')->constrained('users');
            $table->timestampTz('received_at');
            $table->string('status', 16)->default(CustomerPaymentStatus::Posted->value);
            $table->foreignId('money_transaction_id')->nullable()->constrained('money_transactions');
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->timestampTz('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestampsTz();
        });

        Pg::enumCheck('customer_payments', 'status', CustomerPaymentStatus::values());
        Pg::enumCheck('customer_payments', 'method', PaymentMethod::values());

        Schema::create('customer_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_payment_id')->constrained('customer_payments')->cascadeOnDelete();
            $table->foreignId('sales_invoice_id')->constrained('sales_invoices');
            $table->decimal('amount_in_payment_currency', 18, 4);
            $table->decimal('amount_in_invoice_currency', 18, 4);
            $table->decimal('rate_used', 18, 8);
            $table->decimal('fx_gain_loss_usd', 18, 4)->default(0);
            $table->timestampsTz();
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('expense_categories');
            $table->string('code', 32)->unique();
            $table->jsonb('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestampsTz();
        });

        Schema::create('recurring_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches');
            $table->foreignId('expense_category_id')->constrained('expense_categories');
            $table->foreignId('cash_box_id')->nullable()->constrained('cash_boxes');
            $table->decimal('amount', 18, 4);
            $table->char('currency', 3);
            $table->foreign('currency')->references('code')->on('currencies');
            $table->string('payee', 150)->nullable();
            $table->text('description')->nullable();
            $table->string('frequency', 16)->default('monthly');
            $table->unsignedTinyInteger('day_of_month')->default(1);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->date('last_generated_for')->nullable();
            $table->boolean('auto_post')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });

        Pg::check('recurring_expenses', 'recurring_expenses_day', 'day_of_month BETWEEN 1 AND 28 AND amount > 0');

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('branch_id')->nullable()->constrained('branches'); // NULL = head office
            $table->foreignId('expense_category_id')->constrained('expense_categories');
            $table->decimal('amount', 18, 4);
            $table->char('currency', 3);
            $table->foreign('currency')->references('code')->on('currencies');
            $table->foreignId('fx_snapshot_id')->constrained('fx_snapshots');
            $table->decimal('amount_usd', 18, 4);
            $table->foreignId('cash_box_id')->constrained('cash_boxes');
            $table->date('expense_date');
            $table->string('payee', 150)->nullable();
            $table->text('description')->nullable();
            $table->foreignId('recurring_expense_id')->nullable()->constrained('recurring_expenses');
            $table->string('status', 32)->default(ExpenseStatus::Posted->value);
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('money_transaction_id')->nullable()->constrained('money_transactions');
            $table->boolean('is_allocated')->default(false);
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->timestampTz('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestampsTz();

            $table->index(['branch_id', 'expense_date']);
            $table->index('status');
        });

        Pg::enumCheck('expenses', 'status', ExpenseStatus::values());
        Pg::check('expenses', 'expenses_positive', 'amount > 0');

        Schema::create('expense_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained('expenses')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches');
            $table->decimal('percentage', 7, 4);
            $table->decimal('amount', 18, 4);
            $table->timestampsTz();
        });

        // Doctor commissions (open question 5) — used only when the feature flag is on.
        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->nullable()->constrained('users'); // NULL = all doctors
            $table->string('basis', 16);
            $table->foreignId('surgery_type_id')->nullable()->constrained('surgery_types');
            $table->foreignId('product_id')->nullable()->constrained('products');
            $table->foreignId('category_id')->nullable()->constrained('categories');
            $table->string('calc_type', 16);
            $table->decimal('value', 18, 4);
            $table->char('currency', 3)->nullable(); // fixed amounts
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Pg::enumCheck('commission_rules', 'basis', CommissionBasis::values());
        Pg::enumCheck('commission_rules', 'calc_type', CommissionCalc::values());

        Schema::create('commission_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained('users');
            $table->foreignId('surgery_id')->constrained('surgeries');
            $table->foreignId('surgery_item_id')->nullable()->constrained('surgery_items');
            $table->foreignId('commission_rule_id')->constrained('commission_rules');
            $table->decimal('amount', 18, 4);
            $table->char('currency', 3);
            $table->foreignId('fx_snapshot_id')->constrained('fx_snapshots');
            $table->string('status', 16)->default(CommissionStatus::Accrued->value);
            $table->foreignId('money_transaction_id')->nullable()->constrained('money_transactions');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampsTz();

            $table->index(['doctor_id', 'status']);
        });

        Pg::enumCheck('commission_entries', 'status', CommissionStatus::values());
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_entries');
        Schema::dropIfExists('commission_rules');
        Schema::dropIfExists('expense_allocations');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('recurring_expenses');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('customer_payment_allocations');
        Schema::dropIfExists('customer_payments');
        Schema::dropIfExists('sales_invoice_items');
        Schema::dropIfExists('sales_invoices');
    }
};
