<?php

namespace App\Services\Money;

use App\Enums\ClosingStatus;
use App\Enums\IssueSeverity;
use App\Enums\IssueType;
use App\Enums\MoneyTransactionType;
use App\Exceptions\Domain\MoneyRuleViolation;
use App\Models\CashBox;
use App\Models\CashBoxClosing;
use App\Models\MoneyTransaction;
use App\Models\User;
use App\Notifications\DocumentEvent;
use App\Services\Issues\IssueService;
use App\Services\Movement\Notifier;
use App\Services\Permissions\BranchScopeResolver;
use App\Support\Locale\Display;
use App\Support\Msg;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Daily cash closing (Module 19, P4-5): count → expected vs counted → a difference needs a
 * reason → submit locks it and books the difference so the box matches the drawer.
 */
class CashClosingService
{
    public function __construct(
        private readonly MoneyLedgerService $ledger,
        private readonly BranchScopeResolver $scope,
        private readonly IssueService $issues,
        private readonly Notifier $notifier,
    ) {}

    /**
     * Today's closing of a box (created as a draft with the expected balance).
     */
    public function start(CashBox $box, User $by, ?string $date = null): CashBoxClosing
    {
        $this->authorize($by, 'cash_boxes.close_day', $box->branch_id);
        $date ??= now(config('orthotrack.timezone'))->toDateString();

        $existing = CashBoxClosing::query()->where('cash_box_id', $box->id)->where('business_date', $date)->first();

        if ($existing !== null) {
            return $existing;
        }

        $startOfDay = now(config('orthotrack.timezone'))->setDateFrom($date)->startOfDay();
        $opening = MoneyTransaction::query()->where('cash_box_id', $box->id)->where('occurred_at', '<', $startOfDay)
            ->latest('occurred_at')->latest('id')->value('balance_after') ?? '0';

        return CashBoxClosing::query()->create([
            'cash_box_id' => $box->id,
            'business_date' => $date,
            'opening_balance' => $opening,
            'expected_balance' => $box->current_balance,
            'last_money_transaction_id' => MoneyTransaction::query()->where('cash_box_id', $box->id)->max('id'),
            'status' => ClosingStatus::Draft,
        ]);
    }

    /**
     * @param  array<string, int>|null  $denominations  note value → count (optional)
     */
    public function submit(CashBoxClosing $closing, string $counted, User $by, ?string $reason = null, ?array $denominations = null): CashBoxClosing
    {
        $box = $closing->cashBox;
        $this->authorize($by, 'cash_boxes.close_day', $box->branch_id);

        $closing = DB::transaction(function () use ($closing, $counted, $by, $reason, $denominations) {
            /** @var CashBoxClosing $closing */
            $closing = CashBoxClosing::query()->with('cashBox')->lockForUpdate()->findOrFail($closing->id);

            if ($closing->status !== ClosingStatus::Draft) {
                throw new MoneyRuleViolation(__('This closing was already submitted.'));
            }

            $countedValue = BigDecimal::of(str_replace(',', '', trim($counted)) ?: '0')->toScale(4, RoundingMode::HalfUp);

            if ($countedValue->isNegative()) {
                throw new MoneyRuleViolation(__('The counted amount cannot be negative.'), 'counted');
            }

            $box = CashBox::query()->lockForUpdate()->findOrFail($closing->cash_box_id);
            $expected = BigDecimal::of($box->current_balance);
            $difference = $countedValue->minus($expected);

            if (! $difference->isZero() && blank($reason)) {
                throw new MoneyRuleViolation(__('Explain the difference of :amount.', ['amount' => Display::money((string) $difference, $box->currency)]), 'reason');
            }

            $adjustment = null;

            if (! $difference->isZero()) {
                $adjustment = $this->ledger->post($box, MoneyTransactionType::ClosingAdjustment, (string) $difference, $by, $closing, description: $reason);
            }

            $closing->forceFill([
                'expected_balance' => (string) $expected,
                'counted_balance' => (string) $countedValue,
                'difference' => (string) $difference,
                'difference_reason' => $difference->isZero() ? null : $reason,
                'denominations' => $denominations,
                'last_money_transaction_id' => MoneyTransaction::query()->where('cash_box_id', $box->id)->max('id'),
                'adjustment_transaction_id' => $adjustment?->id,
                'status' => ClosingStatus::Submitted,
                'counted_by' => $by->id,
                'submitted_at' => now(),
            ])->save();

            if (! $difference->isZero()) {
                $this->issues->open(IssueType::CashDifference, IssueSeverity::Warning, $box->branch_id, $closing,
                    __('Cash difference in :box on :date: :amount', ['box' => $box->label(), 'date' => $closing->business_date->toDateString(), 'amount' => Display::money((string) $difference, $box->currency)]),
                    ['difference' => (string) $difference, 'currency' => $box->currency, 'reason' => $reason]);
            }

            return $closing;
        });

        if (! BigDecimal::of($closing->difference)->isZero() && $box->branch_id !== null) {
            $this->notifier->toBranch('cash_boxes.review_closing', $box->branch_id, new DocumentEvent(
                'cash.difference',
                Msg::key('Cash difference in :box'),
                ['box' => $box->label()],
                Msg::key(':amount — :reason'),
                ['amount' => Display::money($closing->difference, $box->currency), 'reason' => (string) $closing->difference_reason],
                route('filament.admin.resources.cash-box-closings.index'),
                'heroicon-o-scale',
            ), $by);
        }

        return $closing;
    }

    public function review(CashBoxClosing $closing, User $by, ?string $note = null): CashBoxClosing
    {
        $this->authorize($by, 'cash_boxes.review_closing', $closing->cashBox->branch_id);

        if ($closing->status !== ClosingStatus::Submitted) {
            throw new MoneyRuleViolation(__('Only a submitted closing can be reviewed.'));
        }

        $closing->forceFill(['status' => ClosingStatus::Reviewed, 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'review_note' => $note])->save();
        $this->issues->resolveFor($closing, IssueType::CashDifference, $note ?? __('Closing reviewed.'), $by);

        return $closing;
    }

    private function authorize(User $by, string $permission, ?int $branchId): void
    {
        if (! $this->scope->allows($by, $permission, $branchId)) {
            throw new AuthorizationException(__('You are not allowed to do this for this box.'));
        }
    }
}
