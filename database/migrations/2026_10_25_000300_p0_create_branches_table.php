<?php

use App\Enums\BranchStatus;
use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique();
            $table->jsonb('name');
            $table->jsonb('city')->nullable();
            $table->text('address')->nullable();
            $table->string('phone', 32)->nullable();
            // FK to users is added in the users migration (users is created after branches).
            $table->unsignedBigInteger('manager_id')->nullable()->index();
            $table->char('default_currency', 3);
            $table->foreign('default_currency')->references('code')->on('currencies');
            $table->boolean('is_head_office')->default(false);
            $table->string('status', 32)->default(BranchStatus::Active->value)->index();
            $table->timestampTz('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->text('close_reason')->nullable();
            $table->timestampTz('reopened_at')->nullable();
            $table->unsignedBigInteger('reopened_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestampsTz();
        });

        Pg::enumCheck('branches', 'status', BranchStatus::values());
        Pg::check('branches', 'branches_name_has_en', "name ? 'en'");
        Pg::check('branches', 'branches_code_format', "code ~ '^[A-Z0-9]{2,8}$'");
        Pg::check('branches', 'branches_closed_has_reason', "status <> 'closed' OR close_reason IS NOT NULL");

        // Only one head office.
        DB::statement('CREATE UNIQUE INDEX branches_single_head_office ON branches (is_head_office) WHERE is_head_office = true');
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
