<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Shelves / storage spots inside a branch ("A-1", "Fridge").
        Schema::create('branch_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches');
            $table->string('code', 32);
            $table->jsonb('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->unique(['branch_id', 'code']);
        });

        Pg::check('branch_locations', 'branch_locations_name_has_en', "name IS NULL OR name ? 'en'");
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_locations');
    }
};
