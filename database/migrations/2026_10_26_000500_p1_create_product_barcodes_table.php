<?php

use App\Enums\BarcodeSymbology;
use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extra codes that identify a product (supplier codes, old labels, EANs).
        // supplier_id (Phase 4 link to supplier price lists) is added later.
        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('barcode', 128)->unique();
            $table->string('symbology', 32)->default(BarcodeSymbology::Code128->value);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampTz('created_at')->useCurrent();

            $table->index('product_id');
        });

        Pg::enumCheck('product_barcodes', 'symbology', BarcodeSymbology::values());
    }

    public function down(): void
    {
        Schema::dropIfExists('product_barcodes');
    }
};
