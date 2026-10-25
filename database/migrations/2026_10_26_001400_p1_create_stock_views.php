<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Plain SQL that runs on PostgreSQL and on the SQLite preview.
     * stock_value_usd joins fx_snapshots and is added in Phase 4 (until then value is per cost currency).
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE VIEW v_stock_on_hand AS
            SELECT
                product_id || '-' || branch_id AS id,
                product_id,
                branch_id,
                SUM(qty_shelf) AS qty_shelf,
                SUM(qty_in_kits) AS qty_in_kits,
                SUM(qty_at_hospital) AS qty_at_hospital,
                SUM(qty_in_transit) AS qty_in_transit,
                SUM(qty_shelf) AS qty_available,
                SUM(qty_shelf + qty_in_kits + qty_at_hospital + qty_in_transit) AS qty_total
            FROM (
                SELECT
                    product_id,
                    branch_id,
                    CASE WHEN status = 'in_stock' THEN 1 ELSE 0 END AS qty_shelf,
                    CASE WHEN status = 'in_kit' THEN 1 ELSE 0 END AS qty_in_kits,
                    CASE WHEN status = 'out_at_hospital' THEN 1 ELSE 0 END AS qty_at_hospital,
                    CASE WHEN status = 'in_transit' THEN 1 ELSE 0 END AS qty_in_transit
                FROM stock_units
                WHERE status IN ('in_stock', 'in_kit', 'out_at_hospital', 'in_transit')

                UNION ALL

                SELECT
                    product_id,
                    branch_id,
                    CASE WHEN location_type = 'shelf' THEN quantity ELSE 0 END,
                    CASE WHEN location_type = 'kit' THEN quantity ELSE 0 END,
                    CASE WHEN location_type = 'hospital' THEN quantity ELSE 0 END,
                    0
                FROM stock_batches
                WHERE quantity > 0
            ) AS stock
            GROUP BY product_id, branch_id
            SQL);

        DB::statement(<<<'SQL'
            CREATE VIEW v_low_stock AS
            SELECT
                p.id || '-' || b.id AS id,
                p.id AS product_id,
                b.id AS branch_id,
                COALESCE(s.qty_available, 0) AS qty_available,
                COALESCE(s.qty_total, 0) AS qty_total,
                COALESCE(bps.min_stock, p.min_stock_default) AS min_stock,
                bps.reorder_qty AS reorder_qty,
                COALESCE(bps.min_stock, p.min_stock_default) - COALESCE(s.qty_available, 0) AS shortage
            FROM products p
            CROSS JOIN branches b
            LEFT JOIN branch_product_settings bps ON bps.product_id = p.id AND bps.branch_id = b.id
            LEFT JOIN v_stock_on_hand s ON s.product_id = p.id AND s.branch_id = b.id
            WHERE p.is_active = true
              AND b.status = 'active'
              AND COALESCE(bps.min_stock, p.min_stock_default) > 0
              AND COALESCE(s.qty_available, 0) < COALESCE(bps.min_stock, p.min_stock_default)
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_low_stock');
        DB::statement('DROP VIEW IF EXISTS v_stock_on_hand');
    }
};
