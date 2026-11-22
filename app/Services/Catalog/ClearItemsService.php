<?php

namespace App\Services\Catalog;

use App\Models\CreditNote;
use App\Models\CustomerReturn;
use App\Models\KitCheckoutItem;
use App\Models\LandedCost;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\SalesInvoice;
use App\Models\StockBatch;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\StockRequest;
use App\Models\StockUnit;
use App\Models\SupplierReturn;
use App\Models\Transfer;
use App\Models\TransferItem;
use App\Models\User;
use App\Models\WriteOff;
use App\Support\Database\Pg;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Empties the Items list (Stock → Items list) on the client's TEST site, and everything that
 * only exists because of items:
 *  - items with their barcodes, station minimums, kit-template lines and per-item commission rules;
 *  - all stock: serial units, lots, the stock ledger and the nightly stock-value history;
 *  - every document made of item lines: transfers (kit/instrument-only transfers are kept),
 *    stock requests, write-offs, customer and supplier returns (+ their credit notes), stock
 *    counts, purchases (receipts, landed costs, payment allocations) and sales invoices that have
 *    item lines (+ payment allocations);
 *  - the item lines of kit checkouts and surgeries (+ commissions on those lines);
 *  - supplier/customer balance entries of the deleted documents, their approvals, open issues
 *    and attached files.
 *
 * Kept: stations, users, customers, suppliers, patients, surgeries, kits and instruments, cash
 * boxes and every money movement (payments stay; money already received or paid is not undone),
 * expenses, exchange rates, catalog lookups, company documents and the activity log.
 *
 * This hard-deletes stock and ledger rows, which the real site never does, so it is refused
 * anywhere but a test site (ORTHOTRACK_TEST_SITE=true). One DB transaction: all or nothing.
 */
class ClearItemsService
{
    /** Append-only ledgers this needs to delete from (triggers are lifted and put back). */
    private const LEDGERS = ['stock_movements', 'party_ledger_entries'];

    public static function allowed(): bool
    {
        return (bool) config('orthotrack.test_site');
    }

    /**
     * What would be removed, per table (rows), without changing anything.
     *
     * @return array<string, int>
     */
    public function preview(): array
    {
        $plan = $this->plan();

        return array_filter(array_map(fn (Builder $query) => $query->count(), $plan), fn (int $n) => $n > 0);
    }

    /**
     * @return array<string, int> rows removed per table
     */
    public function run(User $by): array
    {
        if (! self::allowed()) {
            throw new RuntimeException('Refused: this is not a test site. Only a site with ORTHOTRACK_TEST_SITE=true in .env can have its items cleared.');
        }

        $files = collect();

        $removed = DB::transaction(function () use (&$files) {
            $files = $this->attachments()->get(['disk', 'path']);

            // Rows that point at doomed rows but are themselves kept: unlink them first.
            DB::table('surgeries')->whereIn('sales_invoice_id', $this->invoicesWithItems())->update(['sales_invoice_id' => null]);
            DB::table('transfers')->whereNotNull('stock_request_id')->update(['stock_request_id' => null]);
            DB::table('kit_checkout_items')->whereIn('issue_id', $this->issues())->update(['issue_id' => null]);

            $this->liftLedgerGuards();

            $removed = [];
            foreach ($this->plan() as $table => $query) {
                $removed[$table] = $query->delete();
            }

            $this->restoreLedgerGuards();

            return array_filter($removed, fn (int $n) => $n > 0);
        });

        // Files only go once the rows are gone for good.
        foreach ($files->groupBy('disk') as $disk => $rows) {
            Storage::disk((string) $disk)->delete($rows->pluck('path')->filter()->all());
        }

        activity('catalog')->causedBy($by)->event('items_cleared')
            ->withProperties(['removed' => $removed])
            ->log('Items list cleared (test site)');

        return $removed;
    }

    /**
     * Delete queries in a safe order (children before parents). Each is built fresh, so the
     * id lists are read at the moment the query runs.
     *
     * @return array<string, Builder>
     */
    private function plan(): array
    {
        $t = fn (string $table) => DB::table($table);

        return [
            // Issues first: their subjects (checkout lines, transfers…) are still there to match.
            'issues' => $t('issues')->whereIn('id', $this->issues()),

            // Attachments, approvals and balance entries of documents that go.
            'attachments' => $this->attachments(),
            'approvals' => $t('approvals')->whereIn('approvable_type', [$this->type(WriteOff::class), $this->type(StockRequest::class)]),
            'party_ledger_entries' => $t('party_ledger_entries')->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q->where('reference_type', $this->type(Purchase::class))->whereIn('reference_id', $t('purchases')->select('id')))
                ->orWhere(fn (Builder $q) => $q->where('reference_type', $this->type(SalesInvoice::class))->whereIn('reference_id', $this->invoicesWithItems()))
                ->orWhere(fn (Builder $q) => $q->where('reference_type', $this->type(LandedCost::class))->whereIn('reference_id', $t('landed_costs')->select('id')))
                ->orWhere(fn (Builder $q) => $q->where('reference_type', $this->type(CreditNote::class))->whereIn('reference_id', $this->returnCreditNotes()))
                ->orWhereIn('reference_type', [$this->type(SupplierReturn::class), $this->type(CustomerReturn::class)])),

            // Clinical: item lines of kit checkouts and surgeries, commissions on them.
            'kit_checkout_items' => $t('kit_checkout_items')->whereIn('id', $this->checkoutItemLines()),
            'commission_entries' => $t('commission_entries')->where(fn (Builder $q) => $q
                ->whereNotNull('surgery_item_id')
                ->orWhereIn('commission_rule_id', $t('commission_rules')->whereNotNull('product_id')->select('id'))),
            'commission_rules' => $t('commission_rules')->whereNotNull('product_id'),
            'surgery_items' => $t('surgery_items'),

            // Sales.
            'customer_payment_allocations' => $t('customer_payment_allocations')->whereIn('sales_invoice_id', $this->invoicesWithItems()),
            'sales_invoices' => $t('sales_invoices')->whereIn('id', $this->invoicesWithItems()), // lines cascade

            // Returns (+ their credit notes), write-offs.
            'customer_return_items' => $t('customer_return_items'),
            'customer_returns' => $t('customer_returns'),
            'supplier_return_items' => $t('supplier_return_items'),
            'supplier_returns' => $t('supplier_returns'),
            'credit_notes' => $t('credit_notes')->whereIn('id', $this->returnCreditNotes()),
            'write_off_items' => $t('write_off_items'),
            'write_offs' => $t('write_offs'),

            // Counts, requests, transfers (a transfer carrying kits or instruments stays, without its items).
            'stock_count_scans' => $t('stock_count_scans'),
            'stock_count_lines' => $t('stock_count_lines'),
            'stock_counts' => $t('stock_counts'),
            'stock_request_items' => $t('stock_request_items'),
            'stock_requests' => $t('stock_requests'),
            'transfer_items' => $t('transfer_items'),
            'transfers' => $t('transfers')->whereIn('id', $this->transfersToDelete()),

            // Purchasing: receipts point at stock; stock points at purchase lines.
            'purchase_receipt_items' => $t('purchase_receipt_items'),
            'purchase_receipts' => $t('purchase_receipts'),
            'landed_cost_allocations' => $t('landed_cost_allocations'),
            'landed_costs' => $t('landed_costs'),
            'supplier_payment_allocations' => $t('supplier_payment_allocations'),

            // Stock.
            'stock_movements' => $t('stock_movements'),
            'stock_units' => $t('stock_units'),
            'stock_batches' => $t('stock_batches'),
            'stock_value_snapshots' => $t('stock_value_snapshots'),
            'purchase_items' => $t('purchase_items'),
            'purchases' => $t('purchases'),

            // The items themselves.
            'product_barcodes' => $t('product_barcodes'),
            'branch_product_settings' => $t('branch_product_settings'),
            'kit_template_items' => $t('kit_template_items')->whereNotNull('product_id'),
            'products' => $t('products'),
        ];
    }

    /** Sales invoices with at least one item line (an invoice without item lines is kept). */
    private function invoicesWithItems(): Builder
    {
        return DB::table('sales_invoice_items')->select('sales_invoice_id');
    }

    /** Credit notes created by a customer or supplier return. */
    private function returnCreditNotes(): Builder
    {
        return DB::table('credit_notes')->select('id')
            ->whereIn('source_type', [$this->type(SupplierReturn::class), $this->type(CustomerReturn::class)]);
    }

    /** Transfers that carry no kit or instrument (those are kept, without their items). */
    private function transfersToDelete(): Builder
    {
        return DB::table('transfers')->select('id')
            ->whereNotIn('id', DB::table('transfer_assets')->select('transfer_id'));
    }

    /** Issues about anything that goes: stock, counts, item transfers, checkout item lines. */
    private function issues(): Builder
    {
        return DB::table('issues')->select('id')->where(fn (Builder $q) => $q
            ->whereIn('subject_type', [
                $this->type(Product::class),
                $this->type(StockUnit::class),
                $this->type(StockBatch::class),
                $this->type(StockCount::class),
                $this->type(StockCountLine::class),
                $this->type(TransferItem::class),
                $this->type(WriteOff::class),
                $this->type(StockRequest::class),
            ])
            ->orWhere(fn (Builder $q) => $q->where('subject_type', $this->type(Transfer::class))->whereIn('subject_id', $this->transfersToDelete()))
            ->orWhere(fn (Builder $q) => $q->where('subject_type', $this->type(KitCheckoutItem::class))->whereIn('subject_id', $this->checkoutItemLines())));
    }

    /** Kit checkout lines about stock (instrument lines are kept). */
    private function checkoutItemLines(): Builder
    {
        return DB::table('kit_checkout_items')->select('id')->where(fn (Builder $q) => $q
            ->whereNotNull('stock_unit_id')->orWhereNotNull('stock_batch_id')->orWhereNotNull('product_id')->orWhereNotNull('surgery_item_id'));
    }

    /** Files attached to anything that goes (photos of a kept kit transfer stay). */
    private function attachments(): Builder
    {
        return DB::table('attachments')->where(fn (Builder $q) => $q
            ->whereIn('attachable_type', $this->deletedTypes())
            ->orWhere(fn (Builder $q) => $q->where('attachable_type', $this->type(Transfer::class))->whereIn('attachable_id', $this->transfersToDelete())));
    }

    /**
     * Morph types whose rows all go.
     *
     * @return list<string>
     */
    private function deletedTypes(): array
    {
        return [
            $this->type(Product::class),
            $this->type(Purchase::class),
            $this->type(SalesInvoice::class),
            $this->type(SupplierReturn::class),
            $this->type(CustomerReturn::class),
            $this->type(WriteOff::class),
            $this->type(StockCount::class),
            $this->type(StockRequest::class),
            $this->type(StockUnit::class),
            $this->type(StockBatch::class),
        ];
    }

    /** @param  class-string<Model>  $class */
    private function type(string $class): string
    {
        return (new $class)->getMorphClass();
    }

    private function liftLedgerGuards(): void
    {
        foreach (self::LEDGERS as $table) {
            if (Pg::isPostgres()) {
                DB::statement("DROP TRIGGER IF EXISTS {$table}_append_only ON {$table}");
            } else {
                DB::statement("DROP TRIGGER IF EXISTS {$table}_append_only_update");
                DB::statement("DROP TRIGGER IF EXISTS {$table}_append_only_delete");
            }
        }
    }

    private function restoreLedgerGuards(): void
    {
        foreach (self::LEDGERS as $table) {
            Pg::appendOnly($table);
        }
    }
}
