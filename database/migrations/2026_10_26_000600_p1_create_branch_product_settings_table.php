<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-branch override of products.min_stock_default.
        Schema::create('branch_product_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('product_id')->constrained('products');
            $table->unsignedInteger('min_stock')->default(0);
            $table->unsignedInteger('max_stock')->nullable();
            $table->unsignedInteger('reorder_qty')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->unique(['branch_id', 'product_id']);
            $table->index('product_id');
        });

        Pg::check('branch_product_settings', 'branch_product_settings_max_over_min', 'max_stock IS NULL OR max_stock >= min_stock');
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_product_settings');
    }
};
