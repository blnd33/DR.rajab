<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 — morning summary: each person's own settings, and every summary as it was sent
 * (so any past morning can be opened again with the numbers of that morning).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // {enabled, time "HH:MM", sections: list|null, branch_id: int|null, skip_fridays}
            $table->json('summary_settings')->nullable();
        });

        Schema::create('morning_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The morning it belongs to (business time); it reports on the day before.
            $table->date('summary_date');
            $table->json('payload');
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'summary_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('morning_summaries');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('summary_settings');
        });
    }
};
