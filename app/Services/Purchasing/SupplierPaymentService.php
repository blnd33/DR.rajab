<?php

namespace App\Services\Purchasing;

use App\Enums\ApprovalType;
use App\Enums\MoneyTransactionType;
use App\Enums\PartyEntryType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use App\Enums\SupplierPaymentStatus;
use App\Exceptions\Domain\MoneyRuleViolation;
use App\Models\CashBox;
use App\Models\FxSnapshot;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\Money\ApprovalService;
use App\Services\Money\FxService;
use App\Services\Money\MoneyLedgerService;
use App\Services\Money\PartyLedgerService;
use App\Services\Numbering\DocumentNumberGenerator;
use App\Services\Permissions\BranchScopeResolver;
use App\Support\Locale\Display;
use App\Support\Money\FxConverter;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Supplier payments (Module 7, P4-9): from a box, allocated to open purchases (oldest due
 * first unless chosen), converted at the payment snapshot with the realized exchange gain /
 * loss vs each invoice's snapshot; above the threshold they wait for approval.
 */
class SupplierPaymentService
{
    public function __construct(
        private readonly MoneyLedgerService $ledger,
        private readonly PartyLedgerService $party,
        private readonly FxService $fx,
        private readonly ApprovalService $approvals,
        private readonly PurchaseService $purchases,
        private readonly BranchScopeResolver $scope,
        private readonly DocumentNumberGenerator $numbers,
    ) {}

    /**
     * @param  array{amount: string|int|float, method?: string, reference?: ?string, paid_at?: ?string, notes?: ?string, allocations?: array<int, string|int|float>}  $data
     *                                                                                                                                                                       allocations: purchase id => amount in the PURCHASE currency
     */
    public function pay(Supplier $supplier, CashBox $box, array $data, User $by): SupplierPayment
    {
        if (! $by->can('supplier_payments.create') || ! $this->scope->allows($by, 'cash_boxes.view_any', $box->branch_id)) {
            throw new AuthorizationException(__('You are not allowed to pay from this box.'));
        }

        $amount = BigDecimal::of((string) $data['amount'])->toScale(4, RoundingMode::HalfUp);

        if (! $amount->isPositive()) {
            throw new MoneyRuleViolation(__('The amount must be above zero.'), 'amount');
        }

        return DB::transaction(function () use ($supplier, $box, $data, $by, $amount) {
            $snapshot = $this->fx->currentOrFail();
            $converter = $this->fx->converter($snapshot);
            $amountUsd = $converter->toUsd((string) $amount, $box->currency);
            $needsApproval = $this->approvals->needed(ApprovalType::SupplierPayment, $amountUsd);

            $payment = SupplierPayment::query()->create([
                'number' => $this->numbers->next('SPY'),
                'supplier_id' => $supplier->id,
                'cash_box_id' => $box->id,
                'amount' => (string) $amount,
                'currency' => $box->currency,
                'fx_snapshot_id' => $snapshot->id,
                'amount_usd' => $amountUsd,
                'method' => PaymentMethod::from($data['method'] ?? PaymentMethod::BankWire->value),
                'reference' => $data['reference'] ?? null,
                'paid_at' => $data['paid_at'] ?? now()->toDateString(),
                'status' => $needsApproval ? SupplierPaymentStatus::PendingApproval : SupplierPaymentStatus::Posted,
                'requested_by' => $by->id,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->allocate($payment, $supplier, $amount, $converter, $data['allocations'] ?? null);

            if ($needsApproval) {
                $this->approvals->request($payment, ApprovalType::SupplierPayment, $box->branch_id, (string) $amount, $box->currency, $amountUsd, $by,
                    __('Payment :number to :supplier', ['number' => $payment->number, 'supplier' => $supplier->name]));
            } else {
                $this->post($payment, $by);
            }

            return $payment->refresh();
        });
    }

    /**
     * Money out of the box, the payable down on the supplier statement (on approval, or at once).
     */
    public function post(SupplierPayment $payment, User $by): SupplierPayment
    {
        return DB::transaction(function () use ($payment, $by) {
            /** @var SupplierPayment $payment */
            $payment = SupplierPayment::query()->with(['allocations.purchase', 'supplier', 'cashBox'])->lockForUpdate()->findOrFail($payment->id);

            if ($payment->money_transaction_id !== null) {
                throw new MoneyRuleViolation(__('This payment was already paid out.'));
            }

            $snapshot = FxSnapshot::query()->findOrFail($payment->fx_snapshot_id);
            $group = (string) Str::uuid();

            $transaction = $this->ledger->post($payment->cashBox, MoneyTransactionType::SupplierPayment, (string) BigDecimal::of($payment->amount)->negated(), $by, $payment, $group,
                __('Payment :number to :supplier', ['number' => $payment->number, 'supplier' => $payment->supplier->name]), $snapshot);

            $allocated = BigDecimal::zero();

            foreach ($payment->allocations as $allocation) {
                $this->party->record($payment->supplier, PartyEntryType::Payment, $allocation->purchase->currency, (string) BigDecimal::of($allocation->amount_in_purchase_currency)->negated(), $payment,
                    __('Payment :number for :purchase', ['number' => $payment->number, 'purchase' => $allocation->purchase->number]), $by, $snapshot);
                $allocated = $allocated->plus($allocation->amount_in_payment_currency);
            }

            $advance = BigDecimal::of($payment->amount)->minus($allocated);

            if ($advance->isPositive()) {
                $this->party->record($payment->supplier, PartyEntryType::Payment, $payment->currency, (string) $advance->negated(), $payment,
                    __('Payment :number (advance)', ['number' => $payment->number]), $by, $snapshot);
            }

            $payment->forceFill(['status' => SupplierPaymentStatus::Posted, 'money_transaction_id' => $transaction->id])->save();

            foreach ($payment->allocations as $allocation) {
                $this->purchases->refreshPaid($allocation->purchase);
            }

            return $payment;
        });
    }

    public function reject(SupplierPayment $payment, User $by, string $reason): SupplierPayment
    {
        if ($payment->status !== SupplierPaymentStatus::PendingApproval) {
            throw new MoneyRuleViolation(__('Only a payment waiting for approval can be rejected.'));
        }

        $payment->forceFill(['status' => SupplierPaymentStatus::Rejected, 'void_reason' => $reason, 'voided_by' => $by->id, 'voided_at' => now()])->save();

        return $payment;
    }

    /**
     * Undo a paid payment: money back into the box, the payable back on the statement.
     */
    public function void(SupplierPayment $payment, string $reason, User $by): SupplierPayment
    {
        if (! $by->can('supplier_payments.approve')) {
            throw new AuthorizationException(__('You are not allowed to void supplier payments.'));
        }

        if (blank($reason)) {
            throw new MoneyRuleViolation(__('A reason is required.'), 'reason');
        }

        return DB::transaction(function () use ($payment, $reason, $by) {
            /** @var SupplierPayment $payment */
            $payment = SupplierPayment::query()->with(['allocations.purchase', 'supplier', 'moneyTransaction'])->lockForUpdate()->findOrFail($payment->id);

            if ($payment->status !== SupplierPaymentStatus::Posted || $payment->moneyTransaction === null) {
                throw new MoneyRuleViolation(__('Only a paid payment can be voided.'));
            }

            $snapshot = FxSnapshot::query()->findOrFail($payment->fx_snapshot_id);
            $this->ledger->reverse($payment->moneyTransaction, $by, $reason);

            $entries = $this->party->statement($payment->supplier)->where('reference_type', $payment->getMorphClass())->where('reference_id', $payment->id)->get();

            foreach ($entries as $entry) {
                $this->party->record($payment->supplier, PartyEntryType::Reversal, $entry->currency, (string) BigDecimal::of($entry->amount)->negated(), $payment,
                    __('Payment :number voided: :reason', ['number' => $payment->number, 'reason' => $reason]), $by, $snapshot);
            }

            $payment->forceFill(['status' => SupplierPaymentStatus::Voided, 'voided_by' => $by->id, 'voided_at' => now(), 'void_reason' => $reason])->save();

            foreach ($payment->allocations as $allocation) {
                $this->purchases->refreshPaid($allocation->purchase);
            }

            return $payment;
        });
    }

    /**
     * @param  array<int, string|int|float>|null  $chosen  purchase id => amount in the purchase currency
     */
    private function allocate(SupplierPayment $payment, Supplier $supplier, BigDecimal $amount, FxConverter $converter, ?array $chosen): void
    {
        $open = Purchase::query()
            ->where('supplier_id', $supplier->id)
            ->where('status', PurchaseStatus::Confirmed)
            ->where('payment_status', '<>', PaymentStatus::Paid)
            ->when($chosen !== null && $chosen !== [], fn ($q) => $q->whereIn('id', array_keys($chosen)))
            ->orderByRaw('due_date IS NULL')->orderBy('due_date')->orderBy('id')
            ->get();

        $left = $amount; // in the payment currency
        $gainLoss = BigDecimal::zero();

        foreach ($open as $purchase) {
            if (! $left->isPositive()) {
                break;
            }

            $outstanding = BigDecimal::of($this->purchases->open($purchase))->minus($this->pendingFor($purchase));

            if (! $outstanding->isPositive()) {
                continue;
            }

            $rate = $converter->rate($payment->currency, $purchase->currency);
            $want = $chosen !== null && isset($chosen[$purchase->id])
                ? BigDecimal::of((string) $chosen[$purchase->id])->toScale(4, RoundingMode::HalfUp)
                : $outstanding;
            $canCover = BigDecimal::of($converter->convert((string) $left, $payment->currency, $purchase->currency));
            $inPurchase = BigDecimal::min($want, $outstanding, $canCover);

            if (! $inPurchase->isPositive()) {
                continue;
            }

            $inPayment = $inPurchase->isEqualTo($canCover)
                ? $left
                : BigDecimal::of($converter->convert((string) $inPurchase, $purchase->currency, $payment->currency));

            // Realized gain / loss: what the debt was worth at the invoice's rates vs what was paid now.
            $booked = BigDecimal::of(FxConverter::with($purchase->snapshot)->toUsd((string) $inPurchase, $purchase->currency));
            $paid = BigDecimal::of($converter->toUsd((string) $inPayment, $payment->currency));
            $gain = $booked->minus($paid)->toScale(4, RoundingMode::HalfUp);
            $gainLoss = $gainLoss->plus($gain);

            $payment->allocations()->create([
                'purchase_id' => $purchase->id,
                'amount_in_payment_currency' => (string) $inPayment,
                'amount_in_purchase_currency' => (string) $inPurchase,
                'rate_used' => $rate,
                'fx_gain_loss_usd' => (string) $gain,
            ]);

            $left = $left->minus($inPayment);
        }

        $payment->forceFill(['fx_gain_loss_usd' => (string) $gainLoss])->save();
    }

    /**
     * Amount of a purchase held by payments still waiting for approval.
     */
    private function pendingFor(Purchase $purchase): BigDecimal
    {
        return BigDecimal::of((string) $purchase->paymentAllocations()
            ->whereHas('payment', fn ($q) => $q->where('status', SupplierPaymentStatus::PendingApproval))
            ->sum('amount_in_purchase_currency'));
    }

    /**
     * Balance owed per currency, formatted (for screens).
     */
    public function owedText(Supplier $supplier): string
    {
        $balances = $this->party->balances($supplier);

        return $balances === [] ? '—' : implode(' · ', array_map(fn ($c, $v) => Display::money($v, $c), array_keys($balances), $balances));
    }
}
