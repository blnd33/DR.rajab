<?php

use App\Enums\AssetMovementType;
use App\Enums\TransferAssetStatus;
use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ledger for kits and instruments — append-only like stock_movements.
        Schema::create('asset_movements', function (Blueprint $table) {
            $table->id();
            $table->uuid('movement_group');
            $table->string('asset_type', 64);
            $table->unsignedBigInteger('asset_id');
            $table->string('type', 32);
            $table->foreignId('from_branch_id')->nullable()->constrained('branches');
            $table->foreignId('to_branch_id')->nullable()->constrained('branches');
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->foreignId('from_kit_id')->nullable()->constrained('kits');
            $table->foreignId('to_kit_id')->nullable()->constrained('kits');
            $table->foreignId('customer_id')->nullable()->constrained('customers');
            $table->foreignId('holder_user_id')->nullable()->constrained('users');
            $table->nullableMorphs('reference');
            $table->text('note')->nullable();
            $table->foreignId('user_id')->constrained('users');
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->nullable();

            $table->index(['asset_type', 'asset_id', 'occurred_at']);
            $table->index('movement_group');
        });

        Pg::enumCheck('asset_movements', 'type', AssetMovementType::values());
        Pg::appendOnly('asset_movements');

        // Kits and loose instruments travel on a normal transfer.
        Schema::create('transfer_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained('transfers')->cascadeOnDelete();
            $table->foreignId('kit_id')->nullable()->constrained('kits');
            $table->foreignId('instrument_id')->nullable()->constrained('instruments');
            $table->string('status', 16)->default(TransferAssetStatus::Pending->value);
            $table->timestampsTz();

            $table->index('transfer_id');
        });

        Pg::enumCheck('transfer_assets', 'status', TransferAssetStatus::values());
        Pg::check('transfer_assets', 'transfer_assets_one_asset', '(kit_id IS NULL) <> (instrument_id IS NULL)');
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_assets');
        Schema::dropIfExists('asset_movements');
    }
};
