<?php

use App\Enums\CountLineResolution;
use App\Enums\CountLineResult;
use App\Enums\IssueSeverity;
use App\Enums\IssueStatus;
use App\Enums\IssueType;
use App\Enums\StockCountScope;
use App\Enums\StockCountStatus;
use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('branch_id')->constrained('branches');
            $table->string('scope', 16)->default(StockCountScope::Full->value);
            $table->foreignId('category_id')->nullable()->constrained('categories');
            $table->foreignId('branch_location_id')->nullable()->constrained('branch_locations');
            $table->string('status', 32)->default(StockCountStatus::InProgress->value);
            $table->timestampTz('snapshot_at');
            $table->foreignId('started_by')->constrained('users');
            $table->timestampTz('started_at');
            $table->foreignId('submitted_by')->nullable()->constrained('users');
            $table->timestampTz('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->index(['branch_id', 'status']);
        });

        Pg::enumCheck('stock_counts', 'scope', StockCountScope::values());
        Pg::enumCheck('stock_counts', 'status', StockCountStatus::values());

        Schema::create('stock_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained('stock_counts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('stock_unit_id')->nullable()->constrained('stock_units');
            $table->foreignId('stock_batch_id')->nullable()->constrained('stock_batches');
            $table->integer('expected_qty')->default(0);
            $table->integer('counted_qty')->default(0);
            $table->string('result', 32)->nullable();
            $table->foreignId('found_branch_id')->nullable()->constrained('branches');
            $table->string('resolution', 32)->default(CountLineResolution::Pending->value);
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestampTz('resolved_at')->nullable();
            $table->text('note')->nullable();
            $table->timestampsTz();

            $table->index(['stock_count_id', 'result']);
            $table->index('stock_unit_id');
        });

        Pg::enumCheck('stock_count_lines', 'result', CountLineResult::values(), nullable: true);
        Pg::enumCheck('stock_count_lines', 'resolution', CountLineResolution::values());

        Schema::create('stock_count_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained('stock_counts')->cascadeOnDelete();
            $table->uuid('client_uuid')->unique();
            $table->text('raw_code');
            $table->jsonb('parsed')->nullable();
            $table->nullableMorphs('resolved');
            $table->unsignedInteger('quantity')->default(1);
            $table->foreignId('scanned_by')->constrained('users');
            $table->timestampTz('scanned_at');
            $table->string('device_label', 120)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('stock_count_id');
        });

        Schema::create('issues', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);
            $table->string('severity', 16)->default(IssueSeverity::Warning->value);
            $table->foreignId('branch_id')->nullable()->constrained('branches');
            $table->nullableMorphs('subject');
            $table->string('title', 191);
            $table->jsonb('details')->nullable();
            $table->string('status', 16)->default(IssueStatus::Open->value);
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestampTz('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'type', 'branch_id']);
        });

        Pg::enumCheck('issues', 'type', IssueType::values());
        Pg::enumCheck('issues', 'severity', IssueSeverity::values());
        Pg::enumCheck('issues', 'status', IssueStatus::values());
    }

    public function down(): void
    {
        Schema::dropIfExists('issues');
        Schema::dropIfExists('stock_count_scans');
        Schema::dropIfExists('stock_count_lines');
        Schema::dropIfExists('stock_counts');
    }
};
