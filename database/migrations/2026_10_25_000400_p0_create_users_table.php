<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            // Stored lowercase (model mutator); unique index on lower() below.
            $table->string('username', 64);
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('password');
            $table->string('locale', 8)->default('en');
            $table->boolean('all_branches')->default(false);
            $table->foreignId('primary_branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('session_timeout_minutes')->nullable();
            $table->text('app_authentication_secret')->nullable();
            $table->text('app_authentication_recovery_codes')->nullable();
            $table->timestampTz('password_changed_at')->nullable();
            $table->timestampTz('last_login_at')->nullable();
            $table->ipAddress('last_login_ip')->nullable();
            $table->timestampTz('deactivated_at')->nullable();
            $table->foreignId('deactivated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->rememberToken();
            $table->timestampsTz();
        });

        DB::statement('CREATE UNIQUE INDEX users_username_unique ON users (lower(username))');
        DB::statement('CREATE UNIQUE INDEX users_email_unique ON users (lower(email)) WHERE email IS NOT NULL');
        Pg::check('users', 'users_username_format', "username ~ '^[a-z0-9._-]{3,64}$'");

        // SQLite (preview only) rebuilds the table to add FKs and would drop its partial indexes.
        if (Pg::isPostgres()) {
            Schema::table('branches', function (Blueprint $table) {
                $table->foreign('manager_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('closed_by')->references('id')->on('users')->nullOnDelete();
                $table->foreign('reopened_by')->references('id')->on('users')->nullOnDelete();
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
                $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });

        // Only used if SESSION_DRIVER=database (Redis is the default).
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        if (Pg::isPostgres()) {
            Schema::table('branches', function (Blueprint $table) {
                $table->dropForeign(['manager_id']);
                $table->dropForeign(['closed_by']);
                $table->dropForeign(['reopened_by']);
                $table->dropForeign(['created_by']);
                $table->dropForeign(['updated_by']);
            });
        }

        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
