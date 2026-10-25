<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite preview: triggers are created inline per table (see App\Support\Database\Pg).
        if (! Pg::isPostgres()) {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        // Used by every append-only table (ledgers, audit log, rate history).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION forbid_update_delete() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Table % is append-only: % is not allowed', TG_TABLE_NAME, TG_OP
                    USING ERRCODE = 'P0001';
            END;
            $$ LANGUAGE plpgsql
        SQL);
    }

    public function down(): void
    {
        if (! Pg::isPostgres()) {
            return;
        }

        DB::statement('DROP FUNCTION IF EXISTS forbid_update_delete() CASCADE');
    }
};
