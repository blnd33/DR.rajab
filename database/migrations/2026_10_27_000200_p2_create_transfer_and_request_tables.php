<?php

use App\Enums\RequestPriority;
use App\Enums\StockRequestStatus;
use App\Enums\TransferLineResolution;
use App\Enums\TransferLineStatus;
use App\Enums\TransferStatus;
use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Requests first: a transfer can come from a request.
        Schema::create('stock_requests', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('requesting_branch_id')->constrained('branches');
            $table->foreignId('preferred_source_branch_id')->nullable()->constrained('branches');
            $table->string('status', 32)->default(StockRequestStatus::Pending->value);
            $table->string('priority', 16)->default(RequestPriority::Normal->value);
            $table->date('needed_by')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestampsTz();

            $table->index(['requesting_branch_id', 'status']);
            $table->index('status');
        });

        Pg::enumCheck('stock_requests', 'status', StockRequestStatus::values());
        Pg::enumCheck('stock_requests', 'priority', RequestPriority::values());

        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('from_branch_id')->constrained('branches');
            $table->foreignId('to_branch_id')->constrained('branches');
            $table->string('status', 32)->default(TransferStatus::Draft->value);
            $table->foreignId('stock_request_id')->nullable()->constrained('stock_requests');
            $table->text('carrier_note')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('sent_by')->nullable()->constrained('users');
            $table->timestampTz('sent_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users');
            $table->timestampTz('received_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestampsTz();

            $table->index(['to_branch_id', 'status']);
            $table->index(['from_branch_id', 'status']);
        });

        Pg::enumCheck('transfers', 'status', TransferStatus::values());
        Pg::check('transfers', 'transfers_other_branch', 'from_branch_id <> to_branch_id');
        Pg::check('transfers', 'transfers_cancel_reason', "status <> 'cancelled' OR cancel_reason IS NOT NULL");

        Schema::create('transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained('transfers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('stock_unit_id')->nullable()->constrained('stock_units');
            $table->foreignId('from_batch_id')->nullable()->constrained('stock_batches');
            $table->foreignId('to_batch_id')->nullable()->constrained('stock_batches');
            $table->unsignedInteger('quantity_sent')->default(0);
            $table->unsignedInteger('quantity_received')->nullable();
            $table->string('line_status', 16)->default(TransferLineStatus::Pending->value);
            $table->string('resolution', 32)->default(TransferLineResolution::None->value);
            $table->text('override_reason')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->index('transfer_id');
            $table->index('stock_unit_id');
        });

        Pg::enumCheck('transfer_items', 'line_status', TransferLineStatus::values());
        Pg::enumCheck('transfer_items', 'resolution', TransferLineResolution::values());

        Schema::create('stock_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_request_id')->constrained('stock_requests')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->unsignedInteger('quantity_requested');
            $table->unsignedInteger('quantity_approved')->nullable();
            $table->foreignId('source_branch_id')->nullable()->constrained('branches');
            $table->foreignId('transfer_id')->nullable()->constrained('transfers');
            $table->text('note')->nullable();
            $table->timestampsTz();

            $table->index('stock_request_id');
        });

        Pg::check('stock_request_items', 'stock_request_items_qty_positive', 'quantity_requested > 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_request_items');
        Schema::dropIfExists('transfer_items');
        Schema::dropIfExists('transfers');
        Schema::dropIfExists('stock_requests');
    }
};
