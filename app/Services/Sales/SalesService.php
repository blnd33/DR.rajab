<?php

namespace App\Services\Sales;

use App\Enums\BillingStatus;
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
use App\Models\Surgery;
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
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sales (Module 13, P4-12): scan units / lots, price in the invoice currency, paid now into
 * a box (credit only with the credit-sales flag). Void reverses stock and money.
 *
 * Pricing: per item (each line its price) or split — one total for all the pieces, shared by
 * each piece's cost share (editable). Every line keeps its cost share and price share (%).
 */
class SalesService
{
    public const PER_ITEM = 'per_item';

    public const SPLIT = 'split';

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
     * @param  array{currency: string, pricing?: ?string, sale_total?: string|int|float|null, customer_id?: ?int, walk_in_name?: ?string, discount?: string|int|float|null, payment_terms?: string, cash_box_id?: ?int, due_date?: ?string, notes?: ?string, override_reason?: ?string, items: list<array{raw?: ?string, stock_unit_id?: ?int, stock_batch_id?: ?int, product_id?: ?int, quantity?: int|null, unit_price?: string|int|float|null, share?: string|int|float|null}>}  $data
     */
    public function sell(Branch $branch, array $data, User $by): SalesInvoice
    {
        if (! $this->scope->allows($by, 'sales.create', $branch->id)) {
            throw new AuthorizationException(__('You are not allowed to sell in this station.'));
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

        $split = ($data['pricing'] ?? self::PER_ITEM) === self::SPLIT;
        $saleTotal = $split ? $this->saleTotal($data) : null;

        return DB::transaction(function () use ($branch, $data, $by, $terms, $customer, $items, $split, $saleTotal) {
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
                'pricing' => $split ? self::SPLIT : self::PER_ITEM,
                'fx_snapshot_id' => $snapshot->id,
                'payment_terms' => $terms,
                'due_date' => match ($terms) {
                    PaymentTerms::Credit => $data['due_date'] ?? now()->addDays(30)->toDateString(),
                    PaymentTerms::Later => filled($data['due_date'] ?? null) ? $data['due_date'] : null,
                    default => null,
                },
                'status' => SalesInvoiceStatus::Draft,
                'notes' => $data['notes'] ?? null,
                'created_by' => $by->id,
            ]);

            $group = (string) Str::uuid();
            $seen = [];
            $sold = [];

            foreach ($items as $line) {
                [$unit, $batch, $product, $quantity] = $this->resolve($branch, $line);

                if ($unit !== null) {
                    if (isset($seen[$unit->id])) {
                        continue;
                    }
                    $seen[$unit->id] = true;
                }

                $price = $split ? null : (filled($line['unit_price'] ?? null)
                    ? BigDecimal::of((string) $line['unit_price'])->toScale(4, RoundingMode::HalfUp)
                    : $this->listPrice($product, $currency, $converter));

                $movement = $unit !== null
                    ? $this->stock->sellUnit($unit, $branch, $customer, $by, $invoice, $group, $data['override_reason'] ?? null)
                    : $this->stock->sellBatch($batch, $quantity, $branch, $customer, $by, $invoice, $group, $data['override_reason'] ?? null);

                $sold[] = [
                    'product' => $product, 'unit' => $unit, 'batch' => $batch, 'quantity' => $quantity, 'price' => $price, 'movement' => $movement,
                    'cost' => $this->costIn((string) $movement->unit_cost, $quantity, (string) $movement->cost_currency, $currency, $converter),
                    'share' => $line['share'] ?? null,
                ];
            }

            // Each piece's share of what the sale cost us.
            $costShares = $this->costShares(array_column($sold, 'cost'), array_column($sold, 'quantity'));

            if ($split) {
                $shares = $this->chosenShares(array_column($sold, 'share'), $costShares);
                $amounts = $this->splitAmount($saleTotal, $shares);
            } else {
                $amounts = array_map(fn (array $s) => $s['price']->multipliedBy($s['quantity'])->toScale(4, RoundingMode::HalfUp), $sold);
            }

            $subtotal = array_reduce($amounts, fn (BigDecimal $sum, BigDecimal $a) => $sum->plus($a), BigDecimal::zero());

            foreach ($sold as $i => $s) {
                $lineTotal = $amounts[$i];
                $price = $s['price'] ?? $lineTotal->dividedBy($s['quantity'], 4, RoundingMode::HalfUp);

                $invoice->items()->create([
                    'product_id' => $s['product']->id,
                    'stock_unit_id' => $s['unit']?->id,
                    'stock_batch_id' => $s['batch']?->id,
                    'quantity' => $s['quantity'],
                    'unit_price' => (string) $price,
                    'line_total' => (string) $lineTotal,
                    'cost_share' => (string) $costShares[$i],
                    'price_share' => $subtotal->isZero() ? null : (string) $lineTotal->multipliedBy(100)->dividedBy($subtotal, 4, RoundingMode::HalfUp),
                    'unit_cost' => $s['movement']->unit_cost,
                    'cost_currency' => $s['movement']->cost_currency,
                    'cost_fx_snapshot_id' => ($s['unit'] ?? $s['batch'])?->cost_fx_snapshot_id,
                    'override_reason' => $s['movement']->is_override ? $s['movement']->override_reason : null,
                ]);
            }

            $discount = BigDecimal::of((string) ($data['discount'] ?? '0') ?: '0')->toScale(4, RoundingMode::HalfUp);

            if ($split && ! $discount->isZero()) {
                throw new MoneyRuleViolation(__('A sale split by percentage has no extra discount — lower the total instead.'), 'discount');
            }

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
                $receipt = null;

                if ($total->isPositive()) {
                    $receipt = $this->ledger->post($box, MoneyTransactionType::SaleReceipt, (string) $total, $by, $invoice, $group, __('Sale :number', ['number' => $invoice->number]), $snapshot);
                }
                $invoice->forceFill([
                    'paid_amount' => (string) $total,
                    'status' => SalesInvoiceStatus::Paid,
                    'paid_at' => now(),
                    'paid_by' => $by->id,
                    'paid_cash_box_id' => $box->id,
                    'money_transaction_id' => $receipt?->id,
                ]);
            } elseif ($terms === PaymentTerms::Later) {
                // Unpaid until someone marks it paid (InvoiceService::markPaid).
                $invoice->forceFill(['status' => SalesInvoiceStatus::Issued]);
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
            throw new AuthorizationException(__('You are not allowed to void sales in this station.'));
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

            // Receipts still standing (one marked paid, then not paid, is already reversed).
            MoneyTransaction::query()->where('reference_type', $invoice->getMorphClass())->where('reference_id', $invoice->id)
                ->where('type', MoneyTransactionType::SaleReceipt)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('money_transactions AS r')->whereColumn('r.reverses_id', 'money_transactions.id'))
                ->get()
                ->each(fn (MoneyTransaction $tx) => $this->ledger->reverse($tx, $by, $reason, $group));

            if ($invoice->payment_terms === PaymentTerms::Credit && $invoice->customer !== null) {
                $this->party->record($invoice->customer, PartyEntryType::Reversal, $invoice->currency, (string) BigDecimal::of($invoice->total)->negated(), $invoice,
                    __('Invoice :number voided: :reason', ['number' => $invoice->number, 'reason' => $reason]), $by, $invoice->snapshot);
            }

            $invoice->forceFill(['status' => SalesInvoiceStatus::Voided, 'voided_by' => $by->id, 'voided_at' => now(), 'void_reason' => $reason])->save();

            if ($invoice->surgery_id !== null) {
                Surgery::query()->whereKey($invoice->surgery_id)->update(['billing_status' => BillingStatus::NotBilled->value]);
            }

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
     * Preview of a split sale before it is made: each piece, what it cost us (in the sale
     * currency), its cost share and — with a total — its amount. Nothing is sold or saved.
     *
     * @param  list<array<string, mixed>>  $items  same lines as sell()
     * @return list<array{product: Product, quantity: int, cost: string, cost_share: string, share: string, amount: ?string}>
     */
    public function quoteSplit(Branch $branch, array $items, string $currency, string|int|float|null $saleTotal = null): array
    {
        $converter = $this->fx->converter($this->fx->currentOrFail());
        $lines = [];
        $seen = [];

        foreach ($items as $line) {
            if (! (filled($line['raw'] ?? null) || filled($line['stock_unit_id'] ?? null) || filled($line['stock_batch_id'] ?? null) || filled($line['product_id'] ?? null))) {
                continue;
            }

            [$unit, $batch, $product, $quantity] = $this->resolve($branch, $line);

            if ($unit !== null && isset($seen[$unit->id])) {
                continue;
            }

            if ($unit !== null) {
                $seen[$unit->id] = true;
            }

            $item = $unit ?? $batch;
            $lines[] = [
                'product' => $product,
                'quantity' => $quantity,
                'cost' => $this->costIn((string) $item->unit_cost, $quantity, (string) $item->cost_currency, $currency, $converter),
                'share' => $line['share'] ?? null,
            ];
        }

        if ($lines === []) {
            return [];
        }

        $costShares = $this->costShares(array_column($lines, 'cost'), array_column($lines, 'quantity'));
        $shares = $this->chosenShares(array_column($lines, 'share'), $costShares, strict: false);
        $total = filled($saleTotal) && BigDecimal::of((string) $saleTotal)->isPositive() ? BigDecimal::of((string) $saleTotal)->toScale(4, RoundingMode::HalfUp) : null;
        $amounts = $total !== null && $this->sumIs100($shares) ? $this->splitAmount($total, $shares) : [];

        return array_map(fn (array $l, int $i) => [
            'product' => $l['product'],
            'quantity' => $l['quantity'],
            'cost' => (string) $l['cost']->toScale(2, RoundingMode::HalfUp),
            'cost_share' => (string) $costShares[$i]->toScale(2, RoundingMode::HalfUp),
            'share' => (string) $shares[$i]->toScale(2, RoundingMode::HalfUp),
            'amount' => isset($amounts[$i]) ? (string) $amounts[$i]->toScale(2, RoundingMode::HalfUp) : null,
        ], $lines, array_keys($lines));
    }

    /**
     * Cost of a line in the sale currency (today's rates).
     */
    private function costIn(string $unitCost, int $quantity, string $from, string $to, FxConverter $converter): BigDecimal
    {
        $amount = (string) BigDecimal::of($unitCost ?: '0')->multipliedBy($quantity);

        return BigDecimal::of($from === '' || $from === $to ? $amount : $converter->convert($amount, $from, $to))->toScale(4, RoundingMode::HalfUp);
    }

    /**
     * Each line's percentage of the total cost; lines without any cost share equally per piece.
     *
     * @param  list<BigDecimal>  $costs
     * @param  list<int>  $quantities
     * @return list<BigDecimal>
     */
    private function costShares(array $costs, array $quantities): array
    {
        $total = array_reduce($costs, fn (BigDecimal $sum, BigDecimal $c) => $sum->plus($c), BigDecimal::zero());
        $basis = $total->isPositive() ? $costs : array_map(fn (int $q) => BigDecimal::of($q), $quantities);
        $whole = $total->isPositive() ? $total : BigDecimal::of(max(1, array_sum($quantities)));

        return $this->toHundred(array_map(fn (BigDecimal $part) => $part->multipliedBy(100)->dividedBy($whole, 4, RoundingMode::HalfUp), $basis));
    }

    /**
     * The percentages typed by hand (all lines, adding up to 100) or the cost shares.
     *
     * @param  list<mixed>  $typed
     * @param  list<BigDecimal>  $costShares
     * @return list<BigDecimal>
     */
    private function chosenShares(array $typed, array $costShares, bool $strict = true): array
    {
        $filled = array_filter($typed, fn ($s) => filled($s));

        if ($filled === []) {
            return $costShares;
        }

        if (count($filled) !== count($typed)) {
            if ($strict) {
                throw new MoneyRuleViolation(__('Give a percentage for every item, or leave them all empty to use the cost shares.'), 'items');
            }

            return array_map(fn ($s, BigDecimal $c) => filled($s) ? BigDecimal::of((string) $s)->toScale(4, RoundingMode::HalfUp) : $c, $typed, $costShares);
        }

        $shares = array_map(fn ($s) => BigDecimal::of((string) $s)->toScale(4, RoundingMode::HalfUp), $typed);

        foreach ($shares as $share) {
            if ($share->isNegative()) {
                throw new MoneyRuleViolation(__('A percentage cannot be negative.'), 'items');
            }
        }

        if ($strict && ! $this->sumIs100($shares)) {
            throw new MoneyRuleViolation(__('The percentages must add up to 100 %.'), 'items');
        }

        return $shares;
    }

    /**
     * @param  list<BigDecimal>  $shares
     */
    private function sumIs100(array $shares): bool
    {
        $sum = array_reduce($shares, fn (BigDecimal $s, BigDecimal $p) => $s->plus($p), BigDecimal::zero());

        return $sum->minus(100)->abs()->isLessThanOrEqualTo('0.01');
    }

    /**
     * Rounded shares nudged so they add up to exactly 100 (the largest takes the difference).
     *
     * @param  list<BigDecimal>  $shares
     * @return list<BigDecimal>
     */
    private function toHundred(array $shares): array
    {
        if ($shares === []) {
            return [];
        }

        $sum = array_reduce($shares, fn (BigDecimal $s, BigDecimal $p) => $s->plus($p), BigDecimal::zero());
        $largest = array_keys($shares, max($shares))[0];
        $shares[$largest] = $shares[$largest]->plus(BigDecimal::of(100)->minus($sum));

        return $shares;
    }

    /**
     * Total × share per line, 4 decimals; the last line takes the rounding rest so the lines
     * add up to the total exactly.
     *
     * @param  list<BigDecimal>  $shares
     * @return list<BigDecimal>
     */
    private function splitAmount(BigDecimal $total, array $shares): array
    {
        $amounts = [];
        $given = BigDecimal::zero();
        $last = count($shares) - 1;

        foreach ($shares as $i => $share) {
            $amounts[$i] = $i === $last
                ? $total->minus($given)
                : $total->multipliedBy($share)->dividedBy(100, 4, RoundingMode::HalfUp);
            $given = $given->plus($amounts[$i]);
        }

        return $amounts;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function saleTotal(array $data): BigDecimal
    {
        try {
            $total = BigDecimal::of((string) ($data['sale_total'] ?? ''))->toScale(4, RoundingMode::HalfUp);
        } catch (MathException) {
            $total = BigDecimal::zero();
        }

        if (! $total->isPositive()) {
            throw new MoneyRuleViolation(__('Type the total price of the sale.'), 'sale_total');
        }

        return $total;
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
