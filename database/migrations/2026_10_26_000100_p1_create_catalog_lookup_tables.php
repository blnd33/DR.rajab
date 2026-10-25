<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories');
            $table->string('code', 32)->unique();
            $table->jsonb('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });

        Pg::check('categories', 'categories_name_has_en', "name ? 'en'");
        Pg::check('categories', 'categories_not_own_parent', 'parent_id IS NULL OR parent_id <> id');

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('country', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });

        Schema::create('uoms', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->jsonb('name');
            $table->timestampsTz();
        });

        Pg::check('uoms', 'uoms_name_has_en', "name ? 'en'");
    }

    public function down(): void
    {
        Schema::dropIfExists('uoms');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('categories');
    }
};
