<?php

use App\Enums\ImportRowAction;
use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Our own import tables (D7) — Filament's importer uses `imports` and has no old → new preview.
        Schema::create('data_imports', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('branch_id')->nullable()->constrained('branches');
            $table->string('original_disk', 32);
            $table->string('original_path');
            $table->string('original_filename');
            $table->string('status', 32)->default(ImportStatus::Uploaded->value);
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('new_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('unchanged_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->unsignedInteger('warning_count')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->string('error_file_path')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['type', 'created_at']);
        });

        Pg::enumCheck('data_imports', 'type', ImportType::values());
        Pg::enumCheck('data_imports', 'status', ImportStatus::values());

        Schema::create('data_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_import_id')->constrained('data_imports')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('action', 16);
            $table->jsonb('raw');
            $table->jsonb('normalized')->nullable();
            $table->jsonb('diff')->nullable();
            $table->jsonb('errors')->nullable();
            $table->jsonb('warnings')->nullable();
            $table->nullableMorphs('target');
            $table->timestampTz('applied_at')->nullable();

            $table->index(['data_import_id', 'action']);
            $table->index(['data_import_id', 'row_number']);
        });

        Pg::enumCheck('data_import_rows', 'action', ImportRowAction::values());
    }

    public function down(): void
    {
        Schema::dropIfExists('data_import_rows');
        Schema::dropIfExists('data_imports');
    }
};
