<?php

namespace App\Services\Sales;

use App\Enums\LocationType;
use App\Enums\MoneyTransactionType;
use App\Enums\PartyEntryType;
use App\Enums\PaymentTerms;
use App\Enums\SalesInvoiceStatus;
use App\Enums\ScanContext;
use App\Enums\StockStatus;
use App\Exceptions\Domain\MoneyRuleViolation;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\Customer;
use App\Models\MoneyTransaction;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\StockBatch;
use App\Models\StockUnit;
use App\Models\User;
use App\Services\Barcode\ScanResolver;
use App\Services\Barcode\ScanResultKind;
use App\Services\Money\FxService;
use App\Services\Money\MoneyLedgerService;
use App\Services\Money\PartyLedgerService;
use App\Services\Numbering\DocumentNumberGenerator;
use App\Services\Permissions\BranchScopeResolver;
use App\Services\Settings\SettingsRepository;
use App\Services\Stock\StockMovementService;
use App\Support\Money\FxConverter;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sales (Module 13, P4-12): scan units / lots, price in the invoice currency, paid now into
 * a box (credit only with the credit-sales flag). Void reverses stock and money.
 */
class SalesService
{
    public function __construct(
        private readonly StockMovementService $stock,
        private readonly MoneyLedgerService $ledger,
        private readonly PartyLedgerService $party,
        private readonly FxService $fx,
        private readonly ScanResolver $scanner,
        private readonly BranchScopeResolver $scope,
        private readonly DocumentNumberGenerator $numbers,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * @param  array{currency: string, customer_id?: ?int, walk_in_name?: ?string, discount?: string|int|float|null, payment_terms?: string, cash_box_id?: ?int, due_date?: ?string, notes?: ?string, override_reason?: ?string, items: list<array{raw?: ?string, stock_unit_id?: ?int, stock_batch_id?: ?int, product_id?: ?int, quantity?: int|null, unit_price?: string|int|float|null}>}  $data
     */
    public function sell(Branch $branch, array $data, User $by): SalesInvoice
    {
        if (! $this->scope->allows($by, 'sales.create', $branch->id)) {
            throw new AuthorizationException(__('You are not allowed to sell in this branch.'));
        }

        $terms = PaymentTerms::from($data['payment_terms'] ?? PaymentTerms::Cash->value);
        $customer = filled($data['customer_id'] ?? null) ? Customer::query()->findOrFail($data['customer_id']) : null;

        if ($terms === PaymentTerms::Credit && (! $this->settings->bool('features.credit_sales') || $customer === null)) {
            throw new MoneyRuleViolation(__('Credit sales are switched off — the sale must be paid now.'), 'payment_terms');
        }

        $items = array_values(array_filter($data['items'] ?? [], fn ($i) => filled($i['raw'] ?? null) || filled($i['stock_unit_id'] ?? null) || filled($i['stock_batch_id'] ?? null) || filled($i['product_id'] ?? null)));

        if ($items === []) {
            throw new MoneyRuleViolation(__('Add at least one item.'), 'items');
        }

        return DB::transaction(function () use ($branch, $data, $by, $terms, $customer, $items) {
            $snapshot = $this->fx->currentOrFail();
            $converter = $this->fx->converter($snapshot);
            $currency = $data['currency'];
            $box = null;

            if ($terms === PaymentTerms::Cash) {
                $box = CashBox::query()->findOrFail($data['cash_box_id'] ?? 0);

                if ($box->currency !== $currency || $box->branch_id !== $branch->id) {
                    throw new MoneyRuleViolation(__('Take the money into a :currency box of :branch.', ['currency' => $currency, 'branch' => $branch->code]), 'cash_box_id');
                }
            }

            $invoice = SalesInvoice::query()->create([
                'number' => $this->numbers->next('INV', $branch),
                'branch_id' => $branch->id,
                'customer_id' => $customer?->id,
                'walk_in_name' => $customer === null ? ($data['walk_in_name'] ?? null) : null,
                'currency' => $currency,
                'fx_snapshot_id' => $snapshot->id,
                'payment_terms' => $terms,
                'due_date' => $terms === PaymentTerms::Credit ? ($data['due_date'] ?? now()->addDays(30)->toDateString()) : null,
                'status' => SalesInvoiceStatus::Draft,
                'notes' => $data['notes'] ?? null,
                'created_by' => $by->id,
            ]);

            $group = (string) Str::uuid();
            $subtotal = BigDecimal::zero();
            $seen = [];

            foreach ($items as $line) {
                [$unit, $batch, $product, $quantity] = $this->resolve($branch, $line);

                if ($unit !== null) {
                    if (isset($seen[$unit->id])) {
                        continue;
                    }
                    $seen[$unit->id] = true;
                }

                $price = filled($line['unit_price'] ?? null)
                    ? BigDecimal::of((string) $line['unit_price'])->toScale(4, RoundingMode::HalfUp)
                    : $this->listPrice($product, $currency, $converter);
                $lineTotal = $price->multipliedBy($quantity)->toScale(4, RoundingMode::HalfUp);

                $movement = $unit !== null
                    ? $this->stock->sellUnit($unit, $branch, $customer, $by, $invoice, $group, $data['override_reason'] ?? null)
                    : $this->stock->sellBatch($batch, $quantity, $branch, $customer, $by, $invoice, $group, $data['override_reason'] ?? null);

                $invoice->items()->create([
                    'product_id' => $product->id,
                    'stock_unit_id' => $unit?->id,
                    'stock_batch_id' => $batch?->id,
                    'quantity' => $quantity,
                    'unit_price' => (string) $price,
                    'line_total' => (string) $lineTotal,
                    'unit_cost' => $movement->unit_cost,
                    'cost_currency' => $movement->cost_currency,
                    'cost_fx_snapshot_id' => ($unit ?? $batch)?->cost_fx_snapshot_id,
                    'override_reason' => $movement->is_override ? $movement->override_reason : null,
                ]);

                $subtotal = $subtotal->plus($lineTotal);
            }

            $discount = BigDecimal::of((string) ($data['discount'] ?? '0') ?: '0')->toScale(4, RoundingMode::HalfUp);

            if ($discount->isNegative() || $discount->isGreaterThan($subtotal)) {
                throw new MoneyRuleViolation(__('The discount must be between zero and the invoice total.'), 'discount');
            }

            $total = $subtotal->minus($discount);
            $invoice->forceFill([
                'subtotal' => (string) $subtotal,
                'discount' => (string) $discount,
                'total' => (string) $total,
                'issued_by' => $by->id,
                'issued_at' => now(),
            ]);

            if ($terms === PaymentTerms::Cash) {
                if ($total->isPositive()) {
                    $this->ledger->post($box, MoneyTransactionType::SaleReceipt, (string) $total, $by, $invoice, $group, __('Sale :number', ['number' => $invoice->number]), $snapshot);
                }
                $invoice->forceFill(['paid_amount' => (string) $total, 'status' => SalesInvoiceStatus::Paid]);
            } else {
                $this->party->record($customer, PartyEntryType::Invoice, $currency, (string) $total, $invoice, __('Invoice :number', ['number' => $invoice->number]), $by, $snapshot);
                $invoice->forceFill(['status' => SalesInvoiceStatus::Issued]);
            }

            $invoice->save();

            return $invoice->refresh();
        });
    }

    public function void(SalesInvoice $invoice, string $reason, User $by): SalesInvoice
    {
        if (! $this->scope->allows($by, 'sales.void', $invoice->branch_id)) {
            throw new AuthorizationException(__('You are not allowed to void sales in this branch.'));
        }

        if (blank($reason)) {
            throw new MoneyRuleViolation(__('A reason is required.'), 'reason');
        }

        return DB::transaction(function () use ($invoice, $reason, $by) {
            /** @var SalesInvoice $invoice */
            $invoice = SalesInvoice::query()->with(['items.stockUnit', 'items.stockBatch', 'branch', 'customer'])->lockForUpdate()->findOrFail($invoice->id);

            if ($invoice->status === SalesInvoiceStatus::Voided) {
                throw new MoneyRuleViolation(__('Invoice :number is already voided.', ['number' => $invoice->number]));
            }

            if ($invoice->paymentAllocations()->whereHas('payment', fn ($q) => $q->where('status', 'posted'))->exists()) {
                throw new MoneyRuleViolation(__('Void the customer payments of this invoice first.'));
            }

            $group = (string) Str::uuid();

            foreach ($invoice->items as $item) {
                $item->stock_unit_id !== null
                    ? $this->stock->unsellUnit($item->stockUnit, $invoice->branch, $by, $invoice, $group, $reason)
                    : $this->stock->unsellBatch($item->stockBatch, $item->quantity, $invoice->branch, $by, $invoice, $group, $reason);
            }

            MoneyTransaction::query()->where('reference_type', $invoice->getMorphClass())->where('reference_id', $invoice->id)
                ->where('type', MoneyTransactionType::SaleReceipt)->get()
                ->each(fn (MoneyTransaction $tx) => $this->ledger->reverse($tx, $by, $reason, $group));

            if ($invoice->payment_terms === PaymentTerms::Credit && $invoice->customer !== null) {
                $this->party->record($invoice->customer, PartyEntryType::Reversal, $invoice->currency, (string) BigDecimal::of($invoice->total)->negated(), $invoice,
                    __('Invoice :number voided: :reason', ['number' => $invoice->number, 'reason' => $reason]), $by, $invoice->snapshot);
            }

            $invoice->forceFill(['status' => SalesInvoiceStatus::Voided, 'voided_by' => $by->id, 'voided_at' => now(), 'void_reason' => $reason])->save();

            return $invoice;
        });
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array{0: ?StockUnit, 1: ?StockBatch, 2: Product, 3: int}
     */
    private function resolve(Branch $branch, array $line): array
    {
        $quantity = max(1, (int) ($line['quantity'] ?? 1));

        if (filled($line['stock_unit_id'] ?? null)) {
            $unit = StockUnit::query()->with('product')->findOrFail($line['stock_unit_id']);

            return [$unit, null, $unit->product, 1];
        }

        if (filled($line['stock_batch_id'] ?? null)) {
            $batch = StockBatch::query()->with('product')->findOrFail($line['stock_batch_id']);

            return [null, $batch, $batch->product, $quantity];
        }

        $product = null;
        $lot = null;

        if (filled($line['raw'] ?? null)) {
            $result = $this->scanner->resolve((string) $line['raw'], ScanContext::Lookup, null, $branch->id);

            if ($result->kind === ScanResultKind::Unit && $result->unit !== null) {
                if ($result->unit->status !== StockStatus::InStock || $result->unit->branch_id !== $branch->id) {
                    throw new MoneyRuleViolation(__('Unit :serial is not on a shelf in :branch.', ['serial' => $result->unit->serial, 'branch' => $branch->code]), 'items');
                }

                return [$result->unit, null, $result->unit->product, 1];
            }

            $product = $result->product;
            $lot = $result->parsed->lot;
        } elseif (filled($line['product_id'] ?? null)) {
            $product = Product::query()->find($line['product_id']);
        }

        if ($product === null) {
            throw new MoneyRuleViolation(__('Code not recognised: :code', ['code' => (string) ($line['raw'] ?? '—')]), 'items');
        }

        if ($product->isSerialTracked()) {
            throw new MoneyRuleViolation(__(':sku is serial-tracked — scan the serial number.', ['sku' => $product->sku]), 'items');
        }

        $batch = StockBatch::query()
            ->where('branch_id', $branch->id)->where('product_id', $product->id)
            ->where('location_type', LocationType::Shelf)->where('quantity', '>=', $quantity)
            ->when(filled($lot), fn ($q) => $q->where('lot', $lot))
            ->orderByRaw('expiry_date IS NULL')->orderBy('expiry_date')->orderBy('id')
            ->get()->first(fn (StockBatch $b) => ! $b->isBlockedByExpiry())
            ?? throw new MoneyRuleViolation(__('No :sku with :qty available for this sale.', ['sku' => $product->sku, 'qty' => $quantity]), 'items');

        return [null, $batch, $product, $quantity];
    }

    /**
     * The list price in a currency at today's rates (for screens), or null without one.
     */
    public function priceFor(Product $product, string $currency): ?string
    {
        if ($product->selling_price === null || $this->fx->current() === null) {
            return null;
        }

        return (string) $this->listPrice($product, $currency, $this->fx->converter())->toScale(2, RoundingMode::HalfUp);
    }

    private function listPrice(Product $product, string $currency, FxConverter $converter): BigDecimal
    {
        if ($product->selling_price === null) {
            throw new MoneyRuleViolation(__(':sku has no selling price — type the price.', ['sku' => $product->sku]), 'items');
        }

        return BigDecimal::of($converter->convert((string) $product->selling_price, $product->selling_currency ?? 'USD', $currency));
    }
}
