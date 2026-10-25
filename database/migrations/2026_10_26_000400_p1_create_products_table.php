<?php

use App\Enums\TrackingType;
use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One master catalog shared by all branches.
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 64)->unique();
            $table->string('gtin', 14)->nullable()->unique();
            $table->jsonb('name');
            $table->jsonb('description')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('categories');
            $table->foreignId('brand_id')->nullable()->constrained('brands');
            $table->foreignId('uom_id')->constrained('uoms');
            $table->string('size', 64)->nullable();
            $table->string('tracking_type', 32);
            $table->boolean('is_implant')->default(false);
            $table->boolean('requires_expiry')->default(true);
            $table->decimal('purchase_price', 18, 4)->nullable();
            $table->char('purchase_currency', 3)->nullable();
            $table->foreign('purchase_currency')->references('code')->on('currencies');
            $table->decimal('selling_price', 18, 4)->nullable();
            $table->char('selling_currency', 3)->nullable();
            $table->foreign('selling_currency')->references('code')->on('currencies');
            $table->unsignedInteger('min_stock_default')->default(0);
            $table->string('photo_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->index(['is_active', 'tracking_type']);
        });

        Pg::enumCheck('products', 'tracking_type', TrackingType::values());
        Pg::check('products', 'products_name_has_en', "name ? 'en'");
        Pg::check('products', 'products_gtin_digits', "gtin IS NULL OR gtin ~ '^[0-9]{8,14}$'");
        Pg::check('products', 'products_prices_not_negative', '(purchase_price IS NULL OR purchase_price >= 0) AND (selling_price IS NULL OR selling_price >= 0)');
        Pg::check('products', 'products_price_has_currency', '(purchase_price IS NULL OR purchase_currency IS NOT NULL) AND (selling_price IS NULL OR selling_currency IS NOT NULL)');
        Pg::trigramIndex('products', 'sku');
        Pg::ginIndex('products', 'name');
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
