<?php

use App\Enums\ScanContext;
use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Codes nobody could match — used to add new barcode formats when real boxes arrive.
        Schema::create('unrecognized_scans', function (Blueprint $table) {
            $table->id();
            $table->text('raw');
            $table->text('raw_hex');
            $table->string('context', 32);
            $table->foreignId('branch_id')->nullable()->constrained('branches');
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->timestampTz('created_at')->useCurrent();

            $table->index('created_at');
        });

        Pg::enumCheck('unrecognized_scans', 'context', ScanContext::values());
    }

    public function down(): void
    {
        Schema::dropIfExists('unrecognized_scans');
    }
};
