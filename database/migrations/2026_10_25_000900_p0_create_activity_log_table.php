<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // spatie/laravel-activitylog columns + branch, device and a searchable reference code.
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable()->index();
            $table->nullableMorphs('causer', 'causer');
            $table->jsonb('properties')->nullable();
            $table->uuid('batch_uuid')->nullable()->index();
            // No FK: the log is append-only and must outlive a deleted (mistaken) branch.
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            // Serial / lot / document number, so the log is searchable by code.
            $table->string('reference_code', 64)->nullable()->index();
            $table->timestampsTz();

            $table->index('created_at');
        });

        Pg::trigramIndex('activity_log', 'reference_code');
        Pg::appendOnly('activity_log');
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
