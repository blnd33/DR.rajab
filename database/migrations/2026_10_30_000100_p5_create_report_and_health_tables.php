<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nightly stock value per branch and cost currency (P5-4): dashboard trend + "as of" reports.
        Schema::create('stock_value_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_date');
            $table->foreignId('branch_id')->constrained('branches');
            $table->char('currency', 3);
            $table->foreign('currency')->references('code')->on('currencies');
            $table->decimal('value', 18, 4);
            $table->decimal('value_usd', 18, 4)->nullable();
            $table->unsignedInteger('units')->default(0);
            $table->foreignId('fx_snapshot_id')->nullable()->constrained('fx_snapshots');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['snapshot_date', 'branch_id', 'currency']);
        });

        // Last run of each scheduled job (P5-6): a job that stops running shows on the health page.
        Schema::create('scheduler_heartbeats', function (Blueprint $table) {
            $table->string('name', 64)->primary();
            $table->timestampTz('last_started_at')->nullable();
            $table->timestampTz('last_finished_at')->nullable();
            $table->string('last_status', 16)->nullable();
            $table->text('last_output')->nullable();
            $table->timestampTz('updated_at')->nullable();
        });

        // Report filters (P5-7).
        Schema::table('expenses', function (Blueprint $table) {
            $table->index(['expense_date', 'expense_category_id'], 'expenses_date_category_idx');
        });
        Schema::table('surgeries', function (Blueprint $table) {
            $table->index(['surgery_date', 'status'], 'surgeries_date_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('surgeries', fn (Blueprint $table) => $table->dropIndex('surgeries_date_status_idx'));
        Schema::table('expenses', fn (Blueprint $table) => $table->dropIndex('expenses_date_category_idx'));
        Schema::dropIfExists('scheduler_heartbeats');
        Schema::dropIfExists('stock_value_snapshots');
    }
};
