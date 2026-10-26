<?php

namespace App\Services\Sales;

use App\Enums\CustomerPaymentStatus;
use App\Enums\MoneyTransactionType;
use App\Enums\PartyEntryType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentTerms;
use App\Enums\SalesInvoiceStatus;
use App\Exceptions\Domain\MoneyRuleViolation;
use App\Models\CashBox;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\FxSnapshot;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Money\FxService;
use App\Services\Money\MoneyLedgerService;
use App\Services\Money\PartyLedgerService;
use App\Services\Numbering\DocumentNumberGenerator;
use App\Services\Permissions\BranchScopeResolver;
use App\Support\Money\FxConverter;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Customer payments for credit sales (P4-13, behind the credit-sales flag): money into a box,
 * allocated to open invoices (oldest first), with the realized exchange gain / loss.
 */
class CustomerPaymentService
{
    public function __construct(
        private readonly MoneyLedgerService $ledger,
        private readonly PartyLedgerService $party,
        private readonly FxService $fx,
        private readonly BranchScopeResolver $scope,
        private readonly DocumentNumberGenerator $numbers,
    ) {}

    /**
     * @param  array{amount: string|int|float, method?: string, reference?: ?string}  $data
     */
    public function receive(Customer $customer, CashBox $box, array $data, User $by): CustomerPayment
    {
        if (! $this->scope->allows($by, 'customer_payments.create', $box->branch_id)) {
            throw new AuthorizationException(__('You are not allowed to take payments into this box.'));
        }

        $amount = BigDecimal::of((string) $data['amount'])->toScale(4, RoundingMode::HalfUp);

        if (! $amount->isPositive()) {
            throw new MoneyRuleViolation(__('The amount must be above zero.'), 'amount');
        }

        return DB::transaction(function () use ($customer, $box, $data, $by, $amount) {
            $snapshot = $this->fx->currentOrFail();
            $converter = $this->fx->converter($snapshot);

            $payment = CustomerPayment::query()->create([
                'number' => $this->numbers->next('RCP', $box->branch),
                'customer_id' => $customer->id,
                'cash_box_id' => $box->id,
                'amount' => (string) $amount,
                'currency' => $box->currency,
                'fx_snapshot_id' => $snapshot->id,
                'method' => PaymentMethod::from($data['method'] ?? PaymentMethod::Cash->value),
                'reference' => $data['reference'] ?? null,
                'received_by' => $by->id,
                'received_at' => now(),
                'status' => CustomerPaymentStatus::Posted,
            ]);

            $group = (string) Str::uuid();
            $transaction = $this->ledger->post($box, MoneyTransactionType::CustomerPayment, (string) $amount, $by, $payment, $group,
                __('Payment :number from :customer', ['number' => $payment->number, 'customer' => $customer->code]), $snapshot);
            $payment->forceFill(['money_transaction_id' => $transaction->id])->save();

            $left = $amount;
            $invoices = SalesInvoice::query()->where('customer_id', $customer->id)->where('payment_terms', PaymentTerms::Credit)
                ->whereIn('status', [SalesInvoiceStatus::Issued, SalesInvoiceStatus::PartiallyPaid])
                ->orderBy('due_date')->orderBy('id')->get();

            foreach ($invoices as $invoice) {
                if (! $left->isPositive()) {
                    break;
                }

                $open = BigDecimal::of($invoice->total)->minus($invoice->paid_amount);
                $canCover = BigDecimal::of($converter->convert((string) $left, $payment->currency, $invoice->currency));
                $inInvoice = BigDecimal::min($open, $canCover);

                if (! $inInvoice->isPositive()) {
                    continue;
                }

                $inPayment = $inInvoice->isEqualTo($canCover) ? $left : BigDecimal::of($converter->convert((string) $inInvoice, $invoice->currency, $payment->currency));
                $gain = BigDecimal::of($converter->toUsd((string) $inPayment, $payment->currency))
                    ->minus(FxConverter::with($invoice->snapshot)->toUsd((string) $inInvoice, $invoice->currency))->toScale(4, RoundingMode::HalfUp);

                $payment->allocations()->create([
                    'sales_invoice_id' => $invoice->id,
                    'amount_in_payment_currency' => (string) $inPayment,
                    'amount_in_invoice_currency' => (string) $inInvoice,
                    'rate_used' => $converter->rate($payment->currency, $invoice->currency),
                    'fx_gain_loss_usd' => (string) $gain,
                ]);

                $this->party->record($customer, PartyEntryType::Payment, $invoice->currency, (string) $inInvoice->negated(), $payment,
                    __('Payment :number for :invoice', ['number' => $payment->number, 'invoice' => $invoice->number]), $by, $snapshot);
                $this->refresh($invoice);
                $left = $left->minus($inPayment);
            }

            if ($left->isPositive()) {
                $this->party->record($customer, PartyEntryType::Payment, $payment->currency, (string) $left->negated(), $payment,
                    __('Payment :number (advance)', ['number' => $payment->number]), $by, $snapshot);
            }

            return $payment->refresh();
        });
    }

    public function void(CustomerPayment $payment, string $reason, User $by): CustomerPayment
    {
        if (! $this->scope->allows($by, 'sales.void', $payment->cashBox->branch_id)) {
            throw new AuthorizationException(__('You are not allowed to void payments here.'));
        }

        if (blank($reason)) {
            throw new MoneyRuleViolation(__('A reason is required.'), 'reason');
        }

        return DB::transaction(function () use ($payment, $reason, $by) {
            /** @var CustomerPayment $payment */
            $payment = CustomerPayment::query()->with(['moneyTransaction', 'customer', 'allocations.invoice'])->lockForUpdate()->findOrFail($payment->id);

            if ($payment->status !== CustomerPaymentStatus::Posted) {
                throw new MoneyRuleViolation(__('This payment is already voided.'));
            }

            $snapshot = FxSnapshot::query()->findOrFail($payment->fx_snapshot_id);
            $this->ledger->reverse($payment->moneyTransaction, $by, $reason);

            foreach ($this->party->statement($payment->customer)->where('reference_type', $payment->getMorphClass())->where('reference_id', $payment->id)->get() as $entry) {
                $this->party->record($payment->customer, PartyEntryType::Reversal, $entry->currency, (string) BigDecimal::of($entry->amount)->negated(), $payment,
                    __('Payment :number voided: :reason', ['number' => $payment->number, 'reason' => $reason]), $by, $snapshot);
            }

            $payment->forceFill(['status' => CustomerPaymentStatus::Voided, 'voided_by' => $by->id, 'voided_at' => now(), 'void_reason' => $reason])->save();

            foreach ($payment->allocations as $allocation) {
                $this->refresh($allocation->invoice);
            }

            return $payment;
        });
    }

    private function refresh(SalesInvoice $invoice): void
    {
        $paid = BigDecimal::of((string) $invoice->paymentAllocations()->whereHas('payment', fn ($q) => $q->where('status', CustomerPaymentStatus::Posted))
            ->sum('amount_in_invoice_currency'))->toScale(4, RoundingMode::HalfUp);

        $invoice->forceFill([
            'paid_amount' => (string) $paid,
            'status' => match (true) {
                $paid->isZero() => SalesInvoiceStatus::Issued,
                $paid->isGreaterThanOrEqualTo($invoice->total) => SalesInvoiceStatus::Paid,
                default => SalesInvoiceStatus::PartiallyPaid,
            },
        ])->save();
    }
}
