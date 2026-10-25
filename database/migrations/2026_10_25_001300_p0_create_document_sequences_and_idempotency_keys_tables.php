<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Gap-free document numbers (TRF-DHK-2026-00042), incremented under row lock.
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16);
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('next_number')->default(1);
            $table->timestampsTz();

            $table->unique(['type', 'branch_id', 'year'])->nullsNotDistinct();
        });

        Pg::check('document_sequences', 'document_sequences_type_format', "type ~ '^[A-Z]{2,8}$'");

        // Offline sync: replaying a request with the same key returns the stored response.
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->uuid('key')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('endpoint', 191);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->timestampTz('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('document_sequences');
    }
};
