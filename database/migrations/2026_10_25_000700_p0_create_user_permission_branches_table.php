<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Per-permission branch scope override ("where").
         * Rows for (user, permission) replace the user's default scope for that permission.
         * branch_id NULL = all branches.
         */
        Schema::create('user_permission_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('permission_id');
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestampsTz();

            $table->unique(['user_id', 'permission_id', 'branch_id'])->nullsNotDistinct();
            $table->index('permission_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permission_branches');
    }
};
