<?php

use App\Enums\CustomerType;
use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hospitals and other customers (Module 11). Created now for returns; kits and
        // surgeries use it in Phase 3, receivables in Phase 4 (price_list_id added then).
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('type', 32)->default(CustomerType::Hospital->value);
            $table->jsonb('name');
            $table->jsonb('city')->nullable();
            $table->text('address')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('contact_person', 120)->nullable();
            $table->string('tax_number', 64)->nullable();
            $table->char('default_currency', 3)->default('USD');
            $table->foreign('default_currency')->references('code')->on('currencies');
            $table->boolean('credit_enabled')->default(false);
            $table->decimal('credit_limit', 18, 4)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });

        Pg::enumCheck('customers', 'type', CustomerType::values());
        Pg::check('customers', 'customers_name_has_en', "name ? 'en'");
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
