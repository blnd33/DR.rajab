<?php

namespace App\Services\Expenses;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Enums\AttachmentKind;
use App\Enums\ExpenseStatus;
use App\Enums\MoneyTransactionType;
use App\Exceptions\Domain\MoneyRuleViolation;
use App\Models\Approval;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FxSnapshot;
use App\Models\RecurringExpense;
use App\Models\User;
use App\Services\Money\ApprovalService;
use App\Services\Money\FxService;
use App\Services\Money\MoneyLedgerService;
use App\Services\Numbering\DocumentNumberGenerator;
use App\Services\Permissions\BranchScopeResolver;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Expenses (Module 21, P4-14): from a box, branch or head office, above the threshold through
 * the approvals queue; a general expense can be spread over branches (percentages = 100).
 */
class ExpenseService
{
    public function __construct(
        private readonly MoneyLedgerService $ledger,
        private readonly FxService $fx,
        private readonly ApprovalService $approvals,
        private readonly BranchScopeResolver $scope,
        private readonly DocumentNumberGenerator $numbers,
    ) {}

    /**
     * @param  array{expense_category_id: int, cash_box_id: int, amount: string|int|float, currency?: ?string, expense_date?: ?string, payee?: ?string, description?: ?string, allocations?: array<int, string|int|float>|null}  $data
     *                                                                                                                                                                                                                                  allocations: branch id => percentage
     * @param  list<UploadedFile>  $receipts
     */
    public function record(?Branch $branch, array $data, User $by, ?RecurringExpense $recurring = null, bool $forceApproval = false, array $receipts = []): Expense
    {
        if (! $this->scope->allows($by, 'expenses.create', $branch?->id)) {
            throw new AuthorizationException(__('You are not allowed to record expenses here.'));
        }

        $box = $this->payingBox($branch?->id, $data, $by);
        $amount = $this->positiveAmount($data['amount']);

        ExpenseCategory::query()->findOrFail($data['expense_category_id']);
        $allocations = $this->allocations($data['allocations'] ?? null, $amount);

        $expense = DB::transaction(function () use ($branch, $data, $by, $recurring, $forceApproval, $box, $amount, $allocations, $receipts) {
            $snapshot = $this->fx->currentOrFail();
            $amountUsd = $this->fx->converter($snapshot)->toUsd((string) $amount, $box->currency);
            $needsApproval = $forceApproval || $this->approvals->needed(ApprovalType::Expense, $amountUsd);

            $expense = Expense::query()->create([
                'number' => $this->numbers->next('EXP', $branch),
                'branch_id' => $branch?->id,
                'expense_category_id' => $data['expense_category_id'],
                'amount' => (string) $amount,
                'currency' => $box->currency,
                'fx_snapshot_id' => $snapshot->id,
                'amount_usd' => $amountUsd,
                'cash_box_id' => $box->id,
                'expense_date' => $data['expense_date'] ?? now()->toDateString(),
                'payee' => $data['payee'] ?? null,
                'description' => $data['description'] ?? null,
                'recurring_expense_id' => $recurring?->id,
                'status' => $needsApproval ? ExpenseStatus::PendingApproval : ExpenseStatus::Posted,
                'requested_by' => $by->id,
                'is_allocated' => $allocations !== [],
            ]);

            foreach ($allocations as $branchId => [$percentage, $share]) {
                $expense->allocations()->create(['branch_id' => $branchId, 'percentage' => $percentage, 'amount' => $share]);
            }

            foreach ($receipts as $receipt) {
                $this->attachReceipt($expense, $receipt, $by);
            }

            if ($needsApproval) {
                $this->approvals->request($expense, ApprovalType::Expense, $branch?->id, (string) $amount, $box->currency, $amountUsd, $by,
                    __('Expense :number (:category)', ['number' => $expense->number, 'category' => $expense->category->name]));
            } else {
                $this->post($expense, $by);
            }

            return $expense;
        });

        return $expense->refresh();
    }

    /**
     * Change an expense. One waiting for approval is simply changed (and its approval request
     * follows). A paid one is corrected in the ledger: the old payment goes back into its box
     * and the corrected amount is paid again, so the box history stays exact. The branch stays.
     *
     * @param  array{expense_category_id: int, cash_box_id: int, amount: string|int|float, currency?: ?string, expense_date?: ?string, payee?: ?string, description?: ?string, allocations?: array<int, string|int|float>|null}  $data
     */
    public function update(Expense $expense, array $data, User $by, ?string $reason = null): Expense
    {
        if (! $this->scope->allows($by, 'expenses.update', $expense->branch_id)) {
            throw new AuthorizationException(__('You are not allowed to edit expenses here.'));
        }

        $box = $this->payingBox($expense->branch_id, $data, $by);
        $amount = $this->positiveAmount($data['amount']);

        ExpenseCategory::query()->findOrFail($data['expense_category_id']);
        $allocations = $this->allocations($data['allocations'] ?? null, $amount);

        $expense = DB::transaction(function () use ($expense, $data, $by, $reason, $box, $amount, $allocations) {
            /** @var Expense $expense */
            $expense = Expense::query()->with(['moneyTransaction', 'category'])->lockForUpdate()->findOrFail($expense->id);

            if (! in_array($expense->status, [ExpenseStatus::PendingApproval, ExpenseStatus::Posted], true)) {
                throw new MoneyRuleViolation(__('Only an expense that is waiting or paid can be edited.'));
            }

            $wasPaid = $expense->status === ExpenseStatus::Posted;

            if ($wasPaid && blank($reason)) {
                throw new MoneyRuleViolation(__('A reason is required.'), 'reason');
            }

            if ($wasPaid && $expense->moneyTransaction !== null) {
                $this->ledger->reverse($expense->moneyTransaction, $by, __('Expense :number edited: :reason', ['number' => $expense->number, 'reason' => (string) $reason]));
            }

            $snapshot = $this->fx->currentOrFail();
            $amountUsd = $this->fx->converter($snapshot)->toUsd((string) $amount, $box->currency);

            $expense->forceFill([
                'expense_category_id' => $data['expense_category_id'],
                'amount' => (string) $amount,
                'currency' => $box->currency,
                'fx_snapshot_id' => $snapshot->id,
                'amount_usd' => $amountUsd,
                'cash_box_id' => $box->id,
                'expense_date' => $data['expense_date'] ?? $expense->expense_date->toDateString(),
                'payee' => $data['payee'] ?? null,
                'description' => $data['description'] ?? null,
                'is_allocated' => $allocations !== [],
                'money_transaction_id' => null,
            ])->save();

            $expense->allocations()->delete();

            foreach ($allocations as $branchId => [$percentage, $share]) {
                $expense->allocations()->create(['branch_id' => $branchId, 'percentage' => $percentage, 'amount' => $share]);
            }

            if ($wasPaid) {
                $this->post($expense, $by);
            } else {
                Approval::query()->where('approvable_type', $expense->getMorphClass())->where('approvable_id', $expense->id)
                    ->where('status', ApprovalStatus::Pending)
                    ->update(['amount' => (string) $amount, 'currency' => $box->currency, 'amount_usd' => $amountUsd]);
            }

            activity('expenses')->performedOn($expense)->causedBy($by)->event('edited')
                ->withProperties(['reason' => $reason])->log('Edited');

            return $expense;
        });

        return $expense->refresh();
    }

    public function post(Expense $expense, User $by): Expense
    {
        return DB::transaction(function () use ($expense, $by) {
            /** @var Expense $expense */
            $expense = Expense::query()->with(['cashBox', 'category'])->lockForUpdate()->findOrFail($expense->id);

            if ($expense->money_transaction_id !== null) {
                throw new MoneyRuleViolation(__('This expense was already paid out.'));
            }

            $transaction = $this->ledger->post($expense->cashBox, MoneyTransactionType::Expense, (string) BigDecimal::of($expense->amount)->negated(), $by, $expense,
                description: trim($expense->category->name.' · '.($expense->payee ?? '').' '.($expense->description ?? '')), snapshot: FxSnapshot::query()->findOrFail($expense->fx_snapshot_id));

            $expense->forceFill(['status' => ExpenseStatus::Posted, 'money_transaction_id' => $transaction->id, 'approved_at' => $expense->approved_at])->save();

            return $expense;
        });
    }

    public function reject(Expense $expense, User $by, string $reason): Expense
    {
        if ($expense->status !== ExpenseStatus::PendingApproval) {
            throw new MoneyRuleViolation(__('Only an expense waiting for approval can be rejected.'));
        }

        $expense->forceFill(['status' => ExpenseStatus::Rejected, 'void_reason' => $reason, 'voided_by' => $by->id, 'voided_at' => now()])->save();

        return $expense;
    }

    public function void(Expense $expense, string $reason, User $by): Expense
    {
        if (! $this->scope->allows($by, 'expenses.approve', $expense->branch_id)) {
            throw new AuthorizationException(__('You are not allowed to void expenses here.'));
        }

        if (blank($reason)) {
            throw new MoneyRuleViolation(__('A reason is required.'), 'reason');
        }

        return DB::transaction(function () use ($expense, $reason, $by) {
            /** @var Expense $expense */
            $expense = Expense::query()->with('moneyTransaction')->lockForUpdate()->findOrFail($expense->id);

            if ($expense->status !== ExpenseStatus::Posted || $expense->moneyTransaction === null) {
                throw new MoneyRuleViolation(__('Only a paid expense can be voided.'));
            }

            $this->ledger->reverse($expense->moneyTransaction, $by, $reason);
            $expense->forceFill(['status' => ExpenseStatus::Voided, 'voided_by' => $by->id, 'voided_at' => now(), 'void_reason' => $reason])->save();

            return $expense;
        });
    }

    /**
     * The box the money comes from: allowed for this user, and in the chosen currency.
     *
     * @param  array<string, mixed>  $data
     */
    private function payingBox(?int $branchId, array $data, User $by): CashBox
    {
        $box = CashBox::query()->findOrFail($data['cash_box_id']);

        if ($box->branch_id !== $branchId && ! $this->scope->allows($by, 'expenses.create', $box->branch_id)) {
            throw new AuthorizationException(__('You are not allowed to pay from this box.'));
        }

        $currency = $data['currency'] ?? null;

        if (filled($currency) && $currency !== $box->currency) {
            throw new MoneyRuleViolation(__('Pay a :currency expense from a :currency box.', ['currency' => $currency]), 'cash_box_id');
        }

        return $box;
    }

    private function positiveAmount(string|int|float $value): BigDecimal
    {
        $amount = BigDecimal::of((string) $value)->toScale(4, RoundingMode::HalfUp);

        if (! $amount->isPositive()) {
            throw new MoneyRuleViolation(__('The amount must be above zero.'), 'amount');
        }

        return $amount;
    }

    public function attachReceipt(Expense $expense, UploadedFile $file, User $by): void
    {
        $path = $file->store('expenses/'.$expense->id, 'local');

        $expense->attachments()->create([
            'kind' => AttachmentKind::Receipt,
            'disk' => 'local',
            'path' => (string) $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 191),
            'mime' => $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'uploaded_by' => $by->id,
        ]);
    }

    /**
     * @param  array<int, string|int|float>|null  $percentages  branch id => %
     * @return array<int, array{0: string, 1: string}> branch id => [percentage, amount]
     */
    private function allocations(?array $percentages, BigDecimal $amount): array
    {
        $percentages = array_filter($percentages ?? [], fn ($p) => filled($p) && (float) $p > 0);

        if ($percentages === []) {
            return [];
        }

        $sum = array_reduce($percentages, fn (BigDecimal $s, $p) => $s->plus((string) $p), BigDecimal::zero());

        if (! $sum->isEqualTo(100)) {
            throw new MoneyRuleViolation(__('The station shares must add up to 100%.'), 'allocations');
        }

        $result = [];
        $left = $amount;
        $last = array_key_last($percentages);

        foreach ($percentages as $branchId => $percentage) {
            $share = $branchId === $last ? $left : $amount->multipliedBy((string) $percentage)->dividedBy(100, 4, RoundingMode::HalfUp);
            $left = $left->minus($share);
            $result[(int) $branchId] = [(string) BigDecimal::of((string) $percentage)->toScale(4, RoundingMode::HalfUp), (string) $share];
        }

        return $result;
    }
}
