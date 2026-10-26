<?php

use App\Enums\CheckoutItemKind;
use App\Enums\CheckoutItemOutcome;
use App\Enums\Gender;
use App\Enums\KitCheckoutStatus;
use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sensitive: phone and notes are encrypted by the model; phone_hash finds a patient by phone.
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->string('file_number', 32)->unique();
            $table->string('full_name', 150);
            $table->text('phone')->nullable();
            $table->char('phone_hash', 64)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->unsignedSmallInteger('birth_year')->nullable();
            $table->string('gender', 16)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('primary_doctor_id')->nullable()->constrained('users');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->index('phone_hash');
            $table->index('primary_doctor_id');
        });

        Pg::enumCheck('patients', 'gender', Gender::values(), nullable: true);
        Pg::trigramIndex('patients', 'full_name');

        Schema::create('surgery_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->jsonb('name');
            $table->foreignId('default_kit_type_id')->nullable()->constrained('kit_types');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestampsTz();
        });

        Schema::create('kit_checkouts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('kit_id')->constrained('kits');
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('doctor_id')->nullable()->constrained('users');
            $table->foreignId('patient_id')->nullable()->constrained('patients');
            $table->date('planned_surgery_date')->nullable();
            $table->timestampTz('due_back_at')->nullable();
            $table->string('status', 32)->default(KitCheckoutStatus::Out->value);
            $table->foreignId('checked_out_by')->constrained('users');
            $table->timestampTz('checked_out_at');
            $table->foreignId('returned_by')->nullable()->constrained('users');
            $table->timestampTz('returned_at')->nullable();
            $table->foreignId('reconciled_by')->nullable()->constrained('users');
            $table->timestampTz('reconciled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'due_back_at']);
            $table->index('kit_id');
        });

        Pg::enumCheck('kit_checkouts', 'status', KitCheckoutStatus::values());

        Schema::table('kits', function (Blueprint $table) {
            $table->foreign('current_checkout_id')->references('id')->on('kit_checkouts');
        });

        // Snapshot of what left with the kit, and the reconciliation result per line.
        Schema::create('kit_checkout_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kit_checkout_id')->constrained('kit_checkouts')->cascadeOnDelete();
            $table->string('item_kind', 16);
            $table->foreignId('instrument_id')->nullable()->constrained('instruments');
            $table->foreignId('stock_unit_id')->nullable()->constrained('stock_units');
            $table->foreignId('stock_batch_id')->nullable()->constrained('stock_batches');
            $table->foreignId('product_id')->nullable()->constrained('products');
            $table->unsignedInteger('qty_out')->default(1);
            $table->unsignedInteger('qty_returned')->default(0);
            $table->unsignedInteger('qty_used')->default(0);
            $table->unsignedInteger('qty_unaccounted')->default(0);
            $table->string('outcome', 32)->default(CheckoutItemOutcome::Pending->value);
            $table->unsignedBigInteger('surgery_item_id')->nullable(); // FK added with surgery_items
            $table->foreignId('issue_id')->nullable()->constrained('issues');
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestampTz('resolved_at')->nullable();
            $table->text('note')->nullable();
            $table->timestampsTz();

            $table->index('kit_checkout_id');
        });

        Pg::enumCheck('kit_checkout_items', 'item_kind', CheckoutItemKind::values());
        Pg::enumCheck('kit_checkout_items', 'outcome', CheckoutItemOutcome::values());
        Pg::check('kit_checkout_items', 'kit_checkout_items_qty', 'qty_returned + qty_used + qty_unaccounted <= qty_out');
    }

    public function down(): void
    {
        Schema::dropIfExists('kit_checkout_items');

        Schema::table('kits', function (Blueprint $table) {
            $table->dropForeign(['current_checkout_id']);
        });

        Schema::dropIfExists('kit_checkouts');
        Schema::dropIfExists('surgery_types');
        Schema::dropIfExists('patients');
    }
};
