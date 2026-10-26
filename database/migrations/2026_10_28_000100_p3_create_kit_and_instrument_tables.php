<?php

use App\Enums\InstrumentStatus;
use App\Enums\KitItemKind;
use App\Enums\KitStatus;
use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kit_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->jsonb('name');
            $table->jsonb('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('instrument_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->jsonb('name');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        // Expected contents per kit type: instruments and implant sizes (a size = a product).
        Schema::create('kit_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kit_type_id')->constrained('kit_types')->cascadeOnDelete();
            $table->string('item_kind', 16);
            $table->foreignId('instrument_type_id')->nullable()->constrained('instrument_types');
            $table->foreignId('product_id')->nullable()->constrained('products');
            $table->unsignedInteger('expected_qty')->default(1);
            $table->boolean('is_required')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestampsTz();

            $table->index('kit_type_id');
        });

        Pg::enumCheck('kit_template_items', 'item_kind', KitItemKind::values());
        Pg::check('kit_template_items', 'kit_template_items_one_target', '(instrument_type_id IS NULL) <> (product_id IS NULL)');
        Pg::check('kit_template_items', 'kit_template_items_qty_positive', 'expected_qty > 0');

        Schema::create('kits', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('barcode', 128)->unique();
            $table->foreignId('kit_type_id')->constrained('kit_types');
            $table->foreignId('home_branch_id')->constrained('branches');
            $table->foreignId('branch_id')->constrained('branches');
            $table->string('status', 32)->default(KitStatus::Available->value);
            $table->foreignId('customer_id')->nullable()->constrained('customers');
            $table->unsignedBigInteger('current_checkout_id')->nullable(); // FK added with kit_checkouts
            $table->string('photo_path')->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('last_prepared_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->index(['branch_id', 'status']);
        });

        Pg::enumCheck('kits', 'status', KitStatus::values());

        Schema::create('instruments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('barcode', 128)->unique();
            $table->foreignId('instrument_type_id')->constrained('instrument_types');
            $table->string('manufacturer_serial', 64)->nullable();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('kit_id')->nullable()->constrained('kits');
            $table->string('status', 32)->default(InstrumentStatus::Available->value);
            $table->foreignId('holder_user_id')->nullable()->constrained('users');
            $table->foreignId('customer_id')->nullable()->constrained('customers');
            // Purchase cost (asset value); payables arrive with Phase 4.
            $table->decimal('purchase_cost', 18, 4)->nullable();
            $table->char('purchase_currency', 3)->nullable();
            $table->foreign('purchase_currency')->references('code')->on('currencies');
            $table->date('purchased_at')->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->index(['branch_id', 'status']);
            $table->index('kit_id');
        });

        Pg::enumCheck('instruments', 'status', InstrumentStatus::values());
        Pg::check('instruments', 'instruments_in_kit_has_kit', "status <> 'in_kit' OR kit_id IS NOT NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('instruments');
        Schema::dropIfExists('kits');
        Schema::dropIfExists('kit_template_items');
        Schema::dropIfExists('instrument_types');
        Schema::dropIfExists('kit_types');
    }
};
