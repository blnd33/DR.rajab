<?php

namespace App\Services\Purchasing;

use App\Enums\AttachmentKind;
use App\Enums\PartyEntryType;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use App\Enums\ReceivingStatus;
use App\Enums\SupplierPaymentStatus;
use App\Exceptions\Domain\MoneyRuleViolation;
use App\Models\Branch;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Money\FxService;
use App\Services\Money\PartyLedgerService;
use App\Services\Numbering\DocumentNumberGenerator;
use App\Services\Permissions\BranchScopeResolver;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Supplier invoices (Module 7, P4-7): draft → confirm (a payable) → receive → pay.
 * The invoice discount is spread over the lines, so the true unit cost is what was paid.
 */
class PurchaseService
{
    public function __construct(
        private readonly FxService $fx,
        private readonly PartyLedgerService $party,
        private readonly BranchScopeResolver $scope,
        private readonly DocumentNumberGenerator $numbers,
    ) {}

    /**
     * @param  array{supplier_invoice_number: string, invoice_date: string, due_date?: ?string, currency: string, discount?: string|int|float|null, notes?: ?string, lines: list<array{product_id: int, quantity: int, unit_price: string|int|float, expected_lot?: ?string, expected_expiry?: ?string}>}  $data
     */
    public function create(Supplier $supplier, Branch $branch, array $data, User $by): Purchase
    {
        $this->authorize($by, 'purchases.create', $branch->id);
        $invoiceNumber = trim((string) $data['supplier_invoice_number']);

        if ($invoiceNumber === '') {
            throw new MoneyRuleViolation(__('The supplier invoice number is required.'), 'supplier_invoice_number');
        }

        if (Purchase::query()->where('supplier_id', $supplier->id)->where('supplier_invoice_number', $invoiceNumber)->exists()) {
            throw new MoneyRuleViolation(__('Invoice :number of :supplier is already entered.', ['number' => $invoiceNumber, 'supplier' => $supplier->code]), 'supplier_invoice_number');
        }

        $lines = array_values(array_filter($data['lines'] ?? [], fn ($l) => filled($l['product_id'] ?? null)));

        if ($lines === []) {
            throw new MoneyRuleViolation(__('Add at least one product with a quantity.'), 'lines');
        }

        return DB::transaction(function () use ($supplier, $branch, $data, $by, $invoiceNumber, $lines) {
            $snapshot = $this->fx->currentOrFail();
            $dueDate = $data['due_date'] ?? null;

            if (blank($dueDate) && $supplier->payment_terms_days !== null) {
                $dueDate = now()->setDateFrom($data['invoice_date'])->addDays($supplier->payment_terms_days)->toDateString();
            }

            $purchase = Purchase::query()->create([
                'number' => $this->numbers->next('PUR', $branch),
                'supplier_id' => $supplier->id,
                'supplier_invoice_number' => $invoiceNumber,
                'branch_id' => $branch->id,
                'invoice_date' => $data['invoice_date'],
                'due_date' => $dueDate,
                'currency' => $data['currency'],
                'fx_snapshot_id' => $snapshot->id,
                'status' => PurchaseStatus::Draft,
                'receiving_status' => ReceivingStatus::NotReceived,
                'payment_status' => PaymentStatus::Unpaid,
                'notes' => $data['notes'] ?? null,
                'created_by' => $by->id,
            ]);

            $subtotal = BigDecimal::zero();

            foreach ($lines as $line) {
                $quantity = (int) $line['quantity'];
                $price = BigDecimal::of((string) $line['unit_price'])->toScale(4, RoundingMode::HalfUp);

                if ($quantity < 1 || $price->isNegative()) {
                    throw new MoneyRuleViolation(__('Each line needs a quantity of at least 1 and a price.'), 'lines');
                }

                $total = $price->multipliedBy($quantity)->toScale(4, RoundingMode::HalfUp);
                $subtotal = $subtotal->plus($total);

                $purchase->items()->create([
                    'product_id' => (int) $line['product_id'],
                    'quantity' => $quantity,
                    'unit_price' => (string) $price,
                    'line_total' => (string) $total,
                    'expected_lot' => $line['expected_lot'] ?? null,
                    'expected_expiry' => $line['expected_expiry'] ?? null,
                    'true_unit_cost' => (string) $price,
                ]);
            }

            $discount = BigDecimal::of((string) ($data['discount'] ?? '0') ?: '0')->toScale(4, RoundingMode::HalfUp);

            if ($discount->isNegative() || $discount->isGreaterThan($subtotal)) {
                throw new MoneyRuleViolation(__('The discount must be between zero and the invoice total.'), 'discount');
            }

            $purchase->forceFill(['subtotal' => (string) $subtotal, 'discount' => (string) $discount, 'total' => (string) $subtotal->minus($discount)])->save();
            $this->spreadDiscount($purchase);

            return $purchase->refresh();
        });
    }

    /**
     * Confirm: the invoice becomes a payable on the supplier's statement.
     */
    public function confirm(Purchase $purchase, User $by): Purchase
    {
        $this->authorize($by, 'purchases.create', $purchase->branch_id);

        return DB::transaction(function () use ($purchase, $by) {
            /** @var Purchase $purchase */
            $purchase = Purchase::query()->with('supplier')->lockForUpdate()->findOrFail($purchase->id);

            if ($purchase->status !== PurchaseStatus::Draft) {
                throw new MoneyRuleViolation(__('Purchase :number is :status.', ['number' => $purchase->number, 'status' => $purchase->status->getLabel()]));
            }

            $purchase->forceFill(['status' => PurchaseStatus::Confirmed, 'confirmed_by' => $by->id, 'confirmed_at' => now()])->save();

            $this->party->record($purchase->supplier, PartyEntryType::Invoice, $purchase->currency, (string) $purchase->total, $purchase,
                __('Invoice :number (:supplier_number)', ['number' => $purchase->number, 'supplier_number' => $purchase->supplier_invoice_number]), $by, $purchase->snapshot);

            return $purchase;
        });
    }

    /**
     * Cancel before anything was received, paid or costed.
     */
    public function cancel(Purchase $purchase, string $reason, User $by): Purchase
    {
        $this->authorize($by, 'purchases.create', $purchase->branch_id);

        if (blank($reason)) {
            throw new MoneyRuleViolation(__('A reason is required.'), 'reason');
        }

        return DB::transaction(function () use ($purchase, $reason, $by) {
            /** @var Purchase $purchase */
            $purchase = Purchase::query()->with('supplier')->lockForUpdate()->findOrFail($purchase->id);

            if ($purchase->status === PurchaseStatus::Cancelled) {
                throw new MoneyRuleViolation(__('Purchase :number is :status.', ['number' => $purchase->number, 'status' => $purchase->status->getLabel()]));
            }

            if ($purchase->receipts()->exists() || $purchase->paymentAllocations()->exists() || $purchase->landedCosts()->exists()) {
                throw new MoneyRuleViolation(__('This purchase has receipts, payments or landed costs and cannot be cancelled.'));
            }

            if ($purchase->status === PurchaseStatus::Confirmed) {
                $this->party->record($purchase->supplier, PartyEntryType::Reversal, $purchase->currency, (string) BigDecimal::of($purchase->total)->negated(), $purchase,
                    __('Invoice :number cancelled: :reason', ['number' => $purchase->number, 'reason' => $reason]), $by, $purchase->snapshot);
            }

            $purchase->forceFill(['status' => PurchaseStatus::Cancelled, 'voided_by' => $by->id, 'voided_at' => now(), 'void_reason' => $reason])->save();

            return $purchase;
        });
    }

    /**
     * Paid status from the allocations (cached on the purchase).
     */
    public function refreshPaid(Purchase $purchase): void
    {
        $paid = BigDecimal::of((string) $purchase->paymentAllocations()
            ->whereHas('payment', fn ($q) => $q->where('status', SupplierPaymentStatus::Posted))
            ->sum('amount_in_purchase_currency'))->toScale(4, RoundingMode::HalfUp);
        $total = BigDecimal::of($purchase->total);

        $purchase->forceFill([
            'paid_amount' => (string) $paid,
            'payment_status' => match (true) {
                $paid->isZero() => PaymentStatus::Unpaid,
                $paid->isGreaterThanOrEqualTo($total) => PaymentStatus::Paid,
                default => PaymentStatus::Partial,
            },
        ])->save();
    }

    public function open(Purchase $purchase): string
    {
        return (string) BigDecimal::of($purchase->total)->minus($purchase->paid_amount)->toScale(4, RoundingMode::HalfUp);
    }

    /**
     * True unit cost = price × (total / subtotal) + landed cost per unit.
     */
    private function spreadDiscount(Purchase $purchase): void
    {
        $subtotal = BigDecimal::of($purchase->subtotal);

        foreach ($purchase->items as $item) {
            $net = $subtotal->isZero()
                ? BigDecimal::of($item->unit_price)
                : BigDecimal::of($item->unit_price)->multipliedBy($purchase->total)->dividedBy($subtotal, 4, RoundingMode::HalfUp);

            $item->forceFill(['true_unit_cost' => (string) $net->plus($item->landed_cost_per_unit)->toScale(4, RoundingMode::HalfUp)])->save();
        }
    }

    /**
     * Keep the supplier's invoice (scan / PDF) with the purchase.
     */
    public function attachInvoice(Purchase $purchase, UploadedFile $file, User $by): void
    {
        $this->authorize($by, 'purchases.create', $purchase->branch_id);
        $path = $file->store('purchases/'.$purchase->id, 'local');

        $purchase->attachments()->create([
            'kind' => AttachmentKind::Invoice,
            'disk' => 'local',
            'path' => (string) $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 191),
            'mime' => $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'uploaded_by' => $by->id,
        ]);
    }

    private function authorize(User $by, string $permission, int $branchId): void
    {
        if (! $this->scope->allows($by, $permission, $branchId)) {
            throw new AuthorizationException(__('You are not allowed to do this in this station.'));
        }
    }
}
