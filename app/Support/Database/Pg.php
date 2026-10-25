<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL-specific schema helpers used by migrations
 * (CHECK constraints, append-only triggers, trigram indexes).
 *
 * Production and the test suite run on PostgreSQL. The SQLite branch only exists
 * for the no-database preview (README → "Quick preview"): append-only triggers are
 * kept, CHECK constraints and trigram indexes are skipped.
 */
final class Pg
{
    public static function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    /**
     * Add CHECK (column IN (...)) mirroring a PHP backed enum.
     *
     * @param  list<string>  $values
     */
    public static function enumCheck(string $table, string $column, array $values, bool $nullable = false): void
    {
        $list = implode(', ', array_map(fn (string $v) => DB::getPdo()->quote($v), $values));
        $expr = "{$column} IN ({$list})";

        if ($nullable) {
            $expr = "{$column} IS NULL OR {$expr}";
        }

        self::check($table, "{$table}_{$column}_check", $expr);
    }

    public static function check(string $table, string $name, string $expression): void
    {
        // SQLite cannot add constraints to an existing table; the preview skips them.
        if (! self::isPostgres()) {
            return;
        }

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
    }

    /**
     * Block UPDATE and DELETE on a ledger/audit table. Corrections are new rows.
     */
    public static function appendOnly(string $table): void
    {
        if (! self::isPostgres()) {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                $trigger = "{$table}_append_only_".strtolower($operation);
                DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
                DB::statement("CREATE TRIGGER {$trigger} BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, 'Table {$table} is append-only: {$operation} is not allowed'); END");
            }

            return;
        }

        DB::statement("DROP TRIGGER IF EXISTS {$table}_append_only ON {$table}");
        DB::statement("CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION forbid_update_delete()");
    }

    public static function trigramIndex(string $table, string $column): void
    {
        if (self::isPostgres()) {
            DB::statement("CREATE INDEX {$table}_{$column}_trgm ON {$table} USING gin ({$column} gin_trgm_ops)");
        }
    }

    public static function ginIndex(string $table, string $column): void
    {
        if (self::isPostgres()) {
            DB::statement("CREATE INDEX {$table}_{$column}_gin ON {$table} USING gin ({$column})");
        }
    }

    /**
     * Case-insensitive "contains" for a JSON translatable column (all languages).
     */
    public static function jsonContainsSql(string $column): string
    {
        return self::isPostgres() ? "{$column}::text ILIKE ?" : "LOWER({$column}) LIKE LOWER(?)";
    }
}
