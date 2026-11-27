<?php

use App\Enums\AgentType;
use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Company agents → Sub distributors: a new kind of agent, each working under a distributor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_agents', function (Blueprint $table) {
            // Sub distributor → the distributor they work under.
            $table->foreignId('distributor_id')->nullable()->after('user_id')->constrained('company_agents');
            $table->index(['distributor_id']);
        });

        $this->typeCheck(AgentType::values());
    }

    public function down(): void
    {
        DB::table('company_agents')->where('type', 'sub_distributor')->update(['type' => AgentType::Distributor->value]);

        Schema::table('company_agents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('distributor_id');
        });

        $this->typeCheck(array_values(array_diff(AgentType::values(), ['sub_distributor'])));
    }

    /**
     * @param  list<string>  $types
     */
    private function typeCheck(array $types): void
    {
        if (Pg::isPostgres()) {
            DB::statement('ALTER TABLE company_agents DROP CONSTRAINT IF EXISTS company_agents_type_check');
        }

        Pg::enumCheck('company_agents', 'type', $types);
    }
};
