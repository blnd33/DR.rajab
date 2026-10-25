<?php

use App\Enums\LoginFailureReason;
use App\Enums\Panel;
use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Device list per user ("log out this device"). Sessions themselves live in Redis.
        Schema::create('user_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('session_id_hash', 64)->unique();
            $table->string('panel', 16)->nullable();
            $table->string('device_label', 120)->nullable();
            $table->text('user_agent')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->timestampTz('last_activity_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['user_id', 'revoked_at']);
        });

        Pg::enumCheck('user_sessions', 'panel', Panel::values(), nullable: true);

        Schema::create('login_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('username_tried', 191)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->boolean('successful');
            $table->string('failure_reason', 32)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['ip_address', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['successful', 'created_at']);
        });

        Pg::enumCheck('login_attempts', 'failure_reason', LoginFailureReason::values(), nullable: true);
        Pg::appendOnly('login_attempts');
    }

    public function down(): void
    {
        Schema::dropIfExists('login_attempts');
        Schema::dropIfExists('user_sessions');
    }
};
