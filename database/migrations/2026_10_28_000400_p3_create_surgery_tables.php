<?php

use App\Enums\BillingStatus;
use App\Enums\Laterality;
use App\Enums\SurgeryDoctorRole;
use App\Enums\SurgeryItemSource;
use App\Enums\SurgeryStatus;
use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surgeries', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->uuid('client_uuid')->unique(); // offline draft sync
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('customer_id')->nullable()->constrained('customers'); // hospital
            $table->foreignId('patient_id')->nullable()->constrained('patients');
            $table->foreignId('surgery_type_id')->nullable()->constrained('surgery_types');
            $table->string('laterality', 16)->default(Laterality::NotApplicable->value);
            $table->date('surgery_date');
            $table->foreignId('kit_id')->nullable()->constrained('kits');
            $table->foreignId('kit_checkout_id')->nullable()->constrained('kit_checkouts');
            $table->string('status', 16)->default(SurgeryStatus::Draft->value);
            $table->text('notes')->nullable();
            $table->jsonb('problems')->nullable(); // why a synced draft could not be completed
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('completed_by')->nullable()->constrained('users');
            $table->timestampTz('completed_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            // Billing hook (open question 4); invoice + fx columns arrive with Phase 4.
            $table->decimal('fee_amount', 18, 4)->nullable();
            $table->char('fee_currency', 3)->nullable();
            $table->foreign('fee_currency')->references('code')->on('currencies');
            $table->string('billing_status', 16)->default(BillingStatus::NotBilled->value);
            $table->timestampsTz();

            $table->index(['branch_id', 'surgery_date']);
            $table->index('patient_id');
            $table->index('customer_id');
            $table->index('status');
        });

        Pg::enumCheck('surgeries', 'status', SurgeryStatus::values());
        Pg::enumCheck('surgeries', 'laterality', Laterality::values());
        Pg::enumCheck('surgeries', 'billing_status', BillingStatus::values());
        Pg::check('surgeries', 'surgeries_complete_needs_patient', "status <> 'completed' OR (patient_id IS NOT NULL AND customer_id IS NOT NULL AND surgery_type_id IS NOT NULL)");
        Pg::check('surgeries', 'surgeries_cancel_reason', "status <> 'cancelled' OR cancel_reason IS NOT NULL");

        Schema::create('surgery_doctors', function (Blueprint $table) {
            $table->foreignId('surgery_id')->constrained('surgeries')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->string('role', 16)->default(SurgeryDoctorRole::Lead->value);
            $table->primary(['surgery_id', 'user_id']);
            $table->index('user_id');
        });

        Pg::enumCheck('surgery_doctors', 'role', SurgeryDoctorRole::values());

        Schema::create('surgery_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('surgery_id')->constrained('surgeries')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('stock_unit_id')->nullable()->constrained('stock_units');
            $table->foreignId('stock_batch_id')->nullable()->constrained('stock_batches');
            $table->unsignedInteger('quantity')->default(1);
            $table->string('source', 16)->default(SurgeryItemSource::Shelf->value);
            $table->foreignId('kit_id')->nullable()->constrained('kits');
            $table->decimal('unit_cost', 18, 4)->nullable();
            $table->char('cost_currency', 3)->nullable();
            $table->jsonb('warnings')->nullable();
            $table->text('override_reason')->nullable();
            $table->text('scanned_raw')->nullable();
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users');
            $table->text('reversal_reason')->nullable();
            $table->timestampsTz();

            $table->index('surgery_id');
        });

        Pg::enumCheck('surgery_items', 'source', SurgeryItemSource::values());
        Pg::check('surgery_items', 'surgery_items_unit_or_batch', '(stock_unit_id IS NULL) <> (stock_batch_id IS NULL)');
        Pg::check('surgery_items', 'surgery_items_serial_qty_one', 'stock_unit_id IS NULL OR quantity = 1');

        // A serial can be used in only one live surgery (drafts may share it until one completes).
        DB::statement('CREATE UNIQUE INDEX surgery_items_live_unit ON surgery_items (stock_unit_id) WHERE stock_unit_id IS NOT NULL AND used_at IS NOT NULL AND reversed_at IS NULL');

        Schema::table('kit_checkout_items', function (Blueprint $table) {
            $table->foreign('surgery_item_id')->references('id')->on('surgery_items');
        });
    }

    public function down(): void
    {
        Schema::table('kit_checkout_items', function (Blueprint $table) {
            $table->dropForeign(['surgery_item_id']);
        });

        Schema::dropIfExists('surgery_items');
        Schema::dropIfExists('surgery_doctors');
        Schema::dropIfExists('surgeries');
    }
};
