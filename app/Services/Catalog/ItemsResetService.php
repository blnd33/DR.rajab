<?php

namespace App\Services\Catalog;

use App\Models\CashBox;
use App\Support\Database\Pg;
use Brick\Math\BigDecimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Test site only: empty the Items list and everything that depends on the items — stock,
 * movements and the documents made of items (purchases, sales and their payments, surgeries,
 * transfers, requests, returns, write-offs, counts, kit check-outs). Users, branches, hospitals,
 * suppliers, patients, kits, instruments, cash boxes, expenses and settings stay.
 *
 * The append-only guard of the ledgers is lifted for this one transaction and put back; money
 * rows of the removed documents go too, and every box's balance is recomputed from what is left.
 */
class ItemsResetService
{
    /** Documents whose money / ledger / approval / attachment / issue rows go with them. */
    private const DOCUMENTS = [
        'App\\Models\\Product', 'App\\Models\\StockUnit', 'App\\Models\\StockBatch',
        'App\\Models\\Purchase', 'App\\Models\\PurchaseReceipt', 'App\\Models\\LandedCost', 'App\\Models\\SupplierPayment',
        'App\\Models\\SalesInvoice', 'App\\Models\\CustomerPayment', 'App\\Models\\CreditNote',
        'App\\Models\\Surgery', 'App\\Models\\CommissionEntry', 'App\\Models\\KitCheckout',
        'App\\Models\\Transfer', 'App\\Models\\StockRequest', 'App\\Models\\CustomerReturn', 'App\\Models\\SupplierReturn',
        'App\\Models\\WriteOff', 'App\\Models\\StockCount',
    ];

    /** Tables emptied completely, children before parents. */
    private const TABLES = [
        'customer_payment_allocations', 'customer_payments',
        'supplier_payment_allocations', 'supplier_payments',
        'commission_entries',
        'sales_invoice_items', 'sales_invoices',
        'kit_checkout_items', 'kit_checkouts', 'kit_template_items',
        'surgery_items', 'surgery_doctors', 'surgeries',
        'transfer_items', 'transfer_assets', 'stock_request_items', 'transfers', 'stock_requests',
        'customer_return_items', 'customer_returns', 'supplier_return_items', 'supplier_returns', 'credit_notes',
        'write_off_items', 'write_offs',
        'stock_count_lines', 'stock_count_scans', 'stock_counts',
        'purchase_receipt_items', 'landed_cost_allocations',
        'stock_movements', 'stock_units', 'stock_batches',
        'purchase_receipts', 'landed_costs', 'purchase_items', 'purchases',
        'branch_product_settings', 'product_barcodes', 'products',
        'stock_value_snapshots',
    ];

    /** Ledgers guarded against delete/update (Pg::appendOnly). */
    private const GUARDED = ['stock_movements', 'money_transactions', 'party_ledger_entries'];

    public static function allowed(): bool
    {
        return (bool) config('orthotrack.test_site');
    }

    /**
     * What would be removed: table => rows.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];

        foreach ([...self::TABLES, 'commission_rules'] as $table) {
            $query = DB::table($table);

            if ($table === 'commission_rules') {
                $query->whereNotNull('product_id');
            }

            $counts[$table] = $query->count();
        }

        $counts['money_transactions'] = $this->moneyRows()->count();
        $counts['party_ledger_entries'] = DB::table('party_ledger_entries')->whereIn('reference_type', self::DOCUMENTS)->count();

        return array_filter($counts);
    }

    /**
     * Remove it all (one transaction: everything or nothing).
     *
     * @return array<string, int> table => rows removed
     */
    public function run(): array
    {
        if (! self::allowed()) {
            throw new RuntimeException('Refused: this is not a test site (ORTHOTRACK_TEST_SITE=true is needed).');
        }

        $removed = [];
        $files = [];

        DB::transaction(function () use (&$removed, &$files) {
            $this->lift();

            // Links between the documents and to kits.
            DB::table('surgeries')->update(['sales_invoice_id' => null, 'kit_checkout_id' => null]);
            DB::table('sales_invoices')->update(['surgery_id' => null]);
            DB::table('transfers')->update(['stock_request_id' => null]);
            DB::table('stock_request_items')->update(['transfer_id' => null]);
            DB::table('kits')->update(['current_checkout_id' => null]);

            $moneyIds = $this->moneyRows()->pluck('id')->all();
            DB::table('cash_box_closings')->whereIn('last_money_transaction_id', $moneyIds === [] ? [0] : $moneyIds)->update(['last_money_transaction_id' => null]);

            foreach (self::TABLES as $table) {
                $removed[$table] = DB::table($table)->delete();

                // Commission rules for one item go with the item (their entries are gone by now).
                if ($table === 'commission_entries') {
                    $removed['commission_rules'] = DB::table('commission_rules')->whereNotNull('product_id')->delete();
                }
            }

            // Reversals first (they point at the row they reverse).
            $removed['money_transactions'] = 0;
            foreach (array_chunk(array_reverse($moneyIds), 500) as $chunk) {
                DB::table('money_transactions')->whereIn('reverses_id', $chunk)->delete();
                $removed['money_transactions'] += DB::table('money_transactions')->whereIn('id', $chunk)->delete();
            }

            $removed['party_ledger_entries'] = DB::table('party_ledger_entries')->whereIn('reference_type', self::DOCUMENTS)->delete();
            $removed['approvals'] = DB::table('approvals')->whereIn('approvable_type', self::DOCUMENTS)->delete();
            $removed['issues'] = DB::table('issues')->whereIn('subject_type', self::DOCUMENTS)->delete();

            $attachments = DB::table('attachments')->whereIn('attachable_type', self::DOCUMENTS);
            $files = (clone $attachments)->get(['disk', 'path'])->all();
            $removed['attachments'] = $attachments->delete();

            // Kits and instruments that were out with a removed check-out or transfer come home.
            DB::table('kits')->whereIn('status', ['out_at_hospital', 'in_transit'])
                ->update(['status' => 'available', 'customer_id' => null, 'branch_id' => DB::raw('home_branch_id')]);
            DB::table('instruments')->whereIn('status', ['out_at_hospital', 'in_transit'])
                ->update(['status' => DB::raw("CASE WHEN kit_id IS NULL THEN 'available' ELSE 'in_kit' END"), 'customer_id' => null, 'holder_user_id' => null]);

            $this->rebalanceBoxes();
            $this->guard();
        });

        foreach ($files as $file) {
            Storage::disk((string) $file->disk)->delete((string) $file->path);
        }

        return array_filter($removed);
    }

    /**
     * Money rows of the removed documents, and the rows that reverse them.
     */
    private function moneyRows(): Builder
    {
        return DB::table('money_transactions')->whereIn('reference_type', self::DOCUMENTS)->orderBy('id');
    }

    /**
     * Every box's balance from the money left in it; each row's balance_after again in order.
     */
    private function rebalanceBoxes(): void
    {
        foreach (CashBox::query()->pluck('id') as $boxId) {
            $balance = BigDecimal::zero();

            foreach (DB::table('money_transactions')->where('cash_box_id', $boxId)->orderBy('id')->get(['id', 'amount', 'balance_after']) as $row) {
                $balance = $balance->plus((string) $row->amount);

                if (! BigDecimal::of((string) $row->balance_after)->isEqualTo($balance)) {
                    DB::table('money_transactions')->where('id', $row->id)->update(['balance_after' => (string) $balance]);
                }
            }

            DB::table('cash_boxes')->where('id', $boxId)->update(['current_balance' => (string) $balance]);
        }
    }

    private function lift(): void
    {
        foreach (self::GUARDED as $table) {
            if (Pg::isPostgres()) {
                DB::statement("DROP TRIGGER IF EXISTS {$table}_append_only ON {$table}");
            } else {
                DB::statement("DROP TRIGGER IF EXISTS {$table}_append_only_update");
                DB::statement("DROP TRIGGER IF EXISTS {$table}_append_only_delete");
            }
        }
    }

    private function guard(): void
    {
        foreach (self::GUARDED as $table) {
            Pg::appendOnly($table);
        }
    }
}
