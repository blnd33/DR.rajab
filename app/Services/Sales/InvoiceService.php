<?php

namespace App\Services\Sales;

use App\Enums\BillingStatus;
use App\Enums\InvoiceKind;
use App\Enums\MoneyTransactionType;
use App\Enums\PaymentTerms;
use App\Enums\SalesInvoiceStatus;
use App\Enums\SurgeryStatus;
use App\Exceptions\Domain\MoneyRuleViolation;
use App\Models\CashBox;
use App\Models\Customer;
use App\Models\MoneyTransaction;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\Surgery;
use App\Models\User;
use App\Services\Money\FxService;
use App\Services\Money\MoneyLedgerService;
use App\Services\Numbering\DocumentNumberGenerator;
use App\Services\Permissions\BranchScopeResolver;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Invoices for everything we are paid for: sales and surgeries.
 *
 * An invoice stays unpaid until someone marks it paid into a box; only then the money enters the
 * box (one ledger row). Marking it not paid again reverses that row (with a reason). An unpaid
 * invoice can be edited: buyer, notes, due date, and the amounts (a sale's line amounts and
 * discount, a surgery's fee and currency). The items of a sale cannot change here: stock moved
 * with them, so a wrong item means voiding the sale.
 *
 * Credit invoices (customer payments, behind the credit-sales switch) keep their own flow.
 */
class InvoiceService
{
    public function __construct(
        private readonly MoneyLedgerService $ledger,
        private readonly FxService $fx,
        private readonly BranchScopeResolver $scope,
        private readonly DocumentNumberGenerator $numbers,
    ) {}

    /**
     * The unpaid invoice of a completed surgery's fee. Nothing when there is no fee; the live
     * invoice when there already is one.
     */
    public function forSurgery(Surgery $surgery, User $by): ?SalesInvoice
    {
        return DB::transaction(function () use ($surgery, $by) {
            /** @var Surgery $surgery */
            $surgery = Surgery::query()->with(['branch', 'patient'])->lockForUpdate()->findOrFail($surgery->id);

            if ($surgery->status !== SurgeryStatus::Completed) {
                throw new MoneyRuleViolation(__('Only a completed surgery gets an invoice.'));
            }

            $existing = $this->liveSurgeryInvoice($surgery);

            if ($existing !== null) {
                return $existing;
            }

            if ($surgery->fee_amount === null || blank($surgery->fee_currency) || ! BigDecimal::of($surgery->fee_amount)->isPositive()) {
                return null;
            }

            $snapshot = $this->fx->currentOrFail();
            $amount = (string) BigDecimal::of($surgery->fee_amount)->toScale(4, RoundingMode::HalfUp);

            $invoice = SalesInvoice::query()->create([
                'number' => $this->numbers->next('INV', $surgery->branch),
                'kind' => InvoiceKind::Surgery,
                'branch_id' => $surgery->branch_id,
                'customer_id' => $surgery->customer_id,
                'walk_in_name' => $surgery->customer_id === null ? $surgery->patientName() : null,
                'patient_id' => $surgery->patient_id,
                'surgery_id' => $surgery->id,
                'currency' => $surgery->fee_currency,
                'pricing' => SalesService::PER_ITEM,
                'fx_snapshot_id' => $snapshot->id,
                'subtotal' => $amount,
                'discount' => '0',
                'total' => $amount,
                'paid_amount' => '0',
                'payment_terms' => PaymentTerms::Later,
                'status' => SalesInvoiceStatus::Issued,
                'created_by' => $by->id,
                'issued_by' => $by->id,
                'issued_at' => now(),
            ]);

            $surgery->forceFill(['billing_status' => BillingStatus::Billed, 'fee_fx_snapshot_id' => $snapshot->id])->save();

            return $invoice;
        });
    }

    /**
     * Give a completed surgery its fee and invoice afterwards (it was logged without a fee).
     */
    public function billSurgery(Surgery $surgery, string|int|float $amount, string $currency, User $by): SalesInvoice
    {
        $this->authorize($by, 'invoices.update', $surgery->branch_id);
        $fee = $this->positive($amount);
        $this->checkCurrency($currency);

        if ($this->liveSurgeryInvoice($surgery) !== null) {
            throw new MoneyRuleViolation(__('This surgery already has an invoice.'));
        }

        return DB::transaction(function () use ($surgery, $fee, $currency, $by) {
            $surgery->forceFill(['fee_amount' => (string) $fee, 'fee_currency' => $currency])->save();

            return $this->forSurgery($surgery, $by) ?? throw new MoneyRuleViolation(__('Only a completed surgery gets an invoice.'));
        });
    }

    /**
     * Edit an unpaid invoice.
     *
     * Data: customer_id, walk_in_name, notes, due_date; for a surgery invoice amount + currency;
     * for a sale lines [item id => line amount] and discount.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(SalesInvoice $invoice, array $data, User $by): SalesInvoice
    {
        $this->authorize($by, 'invoices.update', $invoice->branch_id);

        return DB::transaction(function () use ($invoice, $data, $by) {
            /** @var SalesInvoice $invoice */
            $invoice = SalesInvoice::query()->with('items')->lockForUpdate()->findOrFail($invoice->id);

            if (! $invoice->isOpenForMarking()) {
                throw new MoneyRuleViolation(__('Only an unpaid invoice can be edited. Mark it not paid first.'));
            }

            $customerId = filled($data['customer_id'] ?? null) ? (int) $data['customer_id'] : null;

            if ($customerId !== null) {
                Customer::query()->findOrFail($customerId);
            }

            $invoice->forceFill([
                'customer_id' => $customerId,
                'walk_in_name' => $customerId === null && filled($data['walk_in_name'] ?? null) ? trim((string) $data['walk_in_name']) : null,
                'notes' => filled($data['notes'] ?? null) ? (string) $data['notes'] : null,
                'due_date' => filled($data['due_date'] ?? null) ? substr((string) $data['due_date'], 0, 10) : null,
            ]);

            if ($invoice->kind === InvoiceKind::Surgery) {
                $this->updateSurgeryAmount($invoice, $data);
            } else {
                $this->updateSaleAmounts($invoice, $data);
            }

            $invoice->save();

            activity('sales_invoices')->performedOn($invoice)->causedBy($by)->event('edited')
                ->withProperties(['total' => $invoice->total, 'currency' => $invoice->currency])->log('Edited');

            return $invoice->refresh();
        });
    }

    /**
     * The money has come: into the chosen box (same currency as the invoice).
     */
    public function markPaid(SalesInvoice $invoice, CashBox $box, User $by): SalesInvoice
    {
        $this->authorize($by, 'invoices.mark_paid', $invoice->branch_id);

        if ($box->branch_id !== $invoice->branch_id && ! $this->scope->allows($by, 'invoices.mark_paid', $box->branch_id)) {
            throw new AuthorizationException(__('You are not allowed to take money into this box.'));
        }

        return DB::transaction(function () use ($invoice, $box, $by) {
            /** @var SalesInvoice $invoice */
            $invoice = SalesInvoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if (! $invoice->isOpenForMarking()) {
                throw new MoneyRuleViolation(__('Invoice :number is :status.', ['number' => $invoice->number, 'status' => $invoice->status->getLabel()]));
            }

            if ($box->currency !== $invoice->currency) {
                throw new MoneyRuleViolation(__('Take the money into a :currency box.', ['currency' => $invoice->currency]), 'cash_box_id');
            }

            $transaction = null;

            if (BigDecimal::of($invoice->total)->isPositive()) {
                $transaction = $this->ledger->post($box, MoneyTransactionType::SaleReceipt, (string) $invoice->total, $by, $invoice,
                    description: __('Invoice :number paid', ['number' => $invoice->number]));
            }

            $invoice->forceFill([
                'paid_amount' => (string) $invoice->total,
                'status' => SalesInvoiceStatus::Paid,
                'paid_at' => now(),
                'paid_by' => $by->id,
                'paid_cash_box_id' => $box->id,
                'money_transaction_id' => $transaction?->id,
            ])->save();

            return $invoice;
        });
    }

    /**
     * It was marked paid by mistake: the money leaves the box again (reversing row) and the
     * invoice is unpaid (and editable) again.
     */
    public function unmarkPaid(SalesInvoice $invoice, string $reason, User $by): SalesInvoice
    {
        $this->authorize($by, 'invoices.mark_paid', $invoice->branch_id);

        if (blank($reason)) {
            throw new MoneyRuleViolation(__('A reason is required.'), 'reason');
        }

        return DB::transaction(function () use ($invoice, $reason, $by) {
            /** @var SalesInvoice $invoice */
            $invoice = SalesInvoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($invoice->status !== SalesInvoiceStatus::Paid || $invoice->payment_terms === PaymentTerms::Credit) {
                throw new MoneyRuleViolation(__('Only an invoice marked paid can be marked not paid.'));
            }

            $transaction = $this->receipt($invoice);

            if ($transaction !== null) {
                $this->ledger->reverse($transaction, $by, __('Invoice :number not paid: :reason', ['number' => $invoice->number, 'reason' => $reason]));
            }

            $invoice->forceFill([
                'paid_amount' => '0',
                'status' => SalesInvoiceStatus::Issued,
                'payment_terms' => PaymentTerms::Later,
                'paid_at' => null,
                'paid_by' => null,
                'paid_cash_box_id' => null,
                'money_transaction_id' => null,
            ])->save();

            activity('sales_invoices')->performedOn($invoice)->causedBy($by)->event('unpaid')
                ->withProperties(['reason' => $reason])->log('Marked not paid');

            return $invoice;
        });
    }

    /**
     * A surgery is cancelled: its unpaid invoice is voided. A paid one must be marked not paid
     * first (the money would otherwise stay counted).
     */
    public function voidForSurgery(Surgery $surgery, string $reason, User $by): void
    {
        $invoice = $this->liveSurgeryInvoice($surgery);

        if ($invoice === null) {
            return;
        }

        if ($invoice->status !== SalesInvoiceStatus::Issued) {
            throw new MoneyRuleViolation(__('Invoice :number of this surgery is paid. Mark it not paid first.', ['number' => $invoice->number]));
        }

        $invoice->forceFill([
            'status' => SalesInvoiceStatus::Voided,
            'voided_by' => $by->id,
            'voided_at' => now(),
            'void_reason' => $reason,
        ])->save();

        $surgery->forceFill(['billing_status' => BillingStatus::NotBilled])->save();
    }

    public function liveSurgeryInvoice(Surgery $surgery): ?SalesInvoice
    {
        return SalesInvoice::query()->where('surgery_id', $surgery->id)->where('status', '<>', SalesInvoiceStatus::Voided)->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function updateSurgeryAmount(SalesInvoice $invoice, array $data): void
    {
        $amount = $this->positive($data['amount'] ?? $invoice->total);
        $currency = (string) ($data['currency'] ?? $invoice->currency);
        $this->checkCurrency($currency);

        $snapshotId = $invoice->fx_snapshot_id;

        if ($currency !== $invoice->currency) {
            $snapshotId = $this->fx->currentOrFail()->id;
        }

        $invoice->forceFill([
            'currency' => $currency,
            'fx_snapshot_id' => $snapshotId,
            'subtotal' => (string) $amount,
            'discount' => '0',
            'total' => (string) $amount,
        ]);

        // The surgery's fee follows its invoice (graphs and reports read the fee).
        if ($invoice->surgery_id !== null) {
            Surgery::query()->whereKey($invoice->surgery_id)->update([
                'fee_amount' => (string) $amount,
                'fee_currency' => $currency,
                'fee_fx_snapshot_id' => $snapshotId,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data  lines: item id => line amount; discount
     */
    private function updateSaleAmounts(SalesInvoice $invoice, array $data): void
    {
        $typed = (array) ($data['lines'] ?? []);
        $amounts = [];

        foreach ($invoice->items as $item) {
            /** @var SalesInvoiceItem $item */
            $value = $typed[$item->id] ?? $item->line_total;
            $amount = BigDecimal::of((string) $value)->toScale(4, RoundingMode::HalfUp);

            if ($amount->isNegative()) {
                throw new MoneyRuleViolation(__('An amount cannot be below zero.'), 'lines');
            }

            $amounts[$item->id] = $amount;
        }

        $subtotal = array_reduce($amounts, fn (BigDecimal $sum, BigDecimal $a) => $sum->plus($a), BigDecimal::zero());
        $discount = BigDecimal::of((string) (filled($data['discount'] ?? null) ? $data['discount'] : '0'))->toScale(4, RoundingMode::HalfUp);

        if ($discount->isNegative() || $discount->isGreaterThan($subtotal)) {
            throw new MoneyRuleViolation(__('The discount must be between zero and the invoice total.'), 'discount');
        }

        foreach ($invoice->items as $item) {
            $lineTotal = $amounts[$item->id];
            $item->forceFill([
                'line_total' => (string) $lineTotal,
                'unit_price' => (string) $lineTotal->dividedBy(max(1, (int) $item->quantity), 4, RoundingMode::HalfUp),
                'price_share' => $subtotal->isZero() ? null : (string) $lineTotal->multipliedBy(100)->dividedBy($subtotal, 4, RoundingMode::HalfUp),
            ])->save();
        }

        $invoice->forceFill([
            'subtotal' => (string) $subtotal,
            'discount' => (string) $discount,
            'total' => (string) $subtotal->minus($discount),
        ]);
    }

    /**
     * The receipt row of a paid invoice: the one marked, or the one posted at a cash sale.
     */
    private function receipt(SalesInvoice $invoice): ?MoneyTransaction
    {
        if ($invoice->money_transaction_id !== null) {
            return MoneyTransaction::query()->find($invoice->money_transaction_id);
        }

        return MoneyTransaction::query()
            ->where('reference_type', $invoice->getMorphClass())->where('reference_id', $invoice->id)
            ->where('type', MoneyTransactionType::SaleReceipt)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('money_transactions AS r')->whereColumn('r.reverses_id', 'money_transactions.id'))
            ->latest('id')
            ->first();
    }

    private function positive(string|int|float|null $value): BigDecimal
    {
        $amount = BigDecimal::of((string) ($value ?? '0'))->toScale(4, RoundingMode::HalfUp);

        if (! $amount->isPositive()) {
            throw new MoneyRuleViolation(__('The amount must be above zero.'), 'amount');
        }

        return $amount;
    }

    private function checkCurrency(string $currency): void
    {
        if (! in_array($currency, config('orthotrack.currencies'), true)) {
            throw new MoneyRuleViolation(__('Choose a currency.'), 'currency');
        }
    }

    private function authorize(User $by, string $permission, ?int $branchId): void
    {
        if (! $this->scope->allows($by, $permission, $branchId)) {
            throw new AuthorizationException(__('You are not allowed to do this in this branch.'));
        }
    }
}
