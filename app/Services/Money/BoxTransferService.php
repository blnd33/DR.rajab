<?php

namespace App\Services\Money;

use App\Enums\BoxTransferStatus;
use App\Enums\MoneyTransactionType;
use App\Exceptions\Domain\MoneyRuleViolation;
use App\Models\BoxTransfer;
use App\Models\CashBox;
use App\Models\MoneyTransaction;
use App\Models\User;
use App\Services\Numbering\DocumentNumberGenerator;
use App\Services\Permissions\BranchScopeResolver;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cash between boxes / branches in the same currency (Module 19, P4-6): send posts the out
 * leg, receive the in leg; cancel (before receiving) reverses the out leg.
 */
class BoxTransferService
{
    public function __construct(
        private readonly MoneyLedgerService $ledger,
        private readonly FxService $fx,
        private readonly BranchScopeResolver $scope,
        private readonly DocumentNumberGenerator $numbers,
    ) {}

    public function send(CashBox $from, CashBox $to, string $amount, User $by, ?string $note = null, bool $receiveNow = false): BoxTransfer
    {
        $this->authorize($by, $from->branch_id);

        if ($from->id === $to->id || $from->currency !== $to->currency) {
            throw new MoneyRuleViolation(__('Choose another box in the same currency (use an exchange for another currency).'), 'to_cash_box_id');
        }

        $value = BigDecimal::of(str_replace(',', '', trim($amount)) ?: '0')->toScale(4, RoundingMode::HalfUp);

        if (! $value->isPositive()) {
            throw new MoneyRuleViolation(__('The amount must be above zero.'), 'amount');
        }

        $transfer = DB::transaction(function () use ($from, $to, $value, $by, $note) {
            $snapshot = $this->fx->currentOrFail();
            $transfer = BoxTransfer::query()->create([
                'number' => $this->numbers->next('BXT', $from->branch),
                'from_cash_box_id' => $from->id,
                'to_cash_box_id' => $to->id,
                'amount' => (string) $value,
                'currency' => $from->currency,
                'fx_snapshot_id' => $snapshot->id,
                'status' => BoxTransferStatus::Sent,
                'note' => $note,
                'sent_by' => $by->id,
                'sent_at' => now(),
            ]);

            $this->ledger->post($from, MoneyTransactionType::BoxTransferOut, (string) $value->negated(), $by, $transfer, description: __('To :box', ['box' => $to->label()]), snapshot: $snapshot);

            return $transfer;
        });

        return $receiveNow ? $this->receive($transfer, $by) : $transfer;
    }

    public function receive(BoxTransfer $transfer, User $by): BoxTransfer
    {
        $this->authorize($by, $transfer->toBox->branch_id);

        return DB::transaction(function () use ($transfer, $by) {
            /** @var BoxTransfer $transfer */
            $transfer = BoxTransfer::query()->with(['fromBox', 'toBox'])->lockForUpdate()->findOrFail($transfer->id);

            if ($transfer->status !== BoxTransferStatus::Sent) {
                throw new MoneyRuleViolation(__('This transfer is :status.', ['status' => $transfer->status->getLabel()]));
            }

            $this->ledger->post($transfer->toBox, MoneyTransactionType::BoxTransferIn, (string) $transfer->amount, $by, $transfer,
                description: __('From :box', ['box' => $transfer->fromBox->label()]));
            $transfer->forceFill(['status' => BoxTransferStatus::Received, 'received_by' => $by->id, 'received_at' => now()])->save();

            return $transfer;
        });
    }

    public function cancel(BoxTransfer $transfer, string $reason, User $by): BoxTransfer
    {
        $this->authorize($by, $transfer->fromBox->branch_id);

        if (blank($reason)) {
            throw new MoneyRuleViolation(__('A reason is required.'), 'reason');
        }

        return DB::transaction(function () use ($transfer, $reason, $by) {
            /** @var BoxTransfer $transfer */
            $transfer = BoxTransfer::query()->lockForUpdate()->findOrFail($transfer->id);

            if ($transfer->status !== BoxTransferStatus::Sent) {
                throw new MoneyRuleViolation(__('Only a transfer on its way can be cancelled.'));
            }

            $out = MoneyTransaction::query()->where('reference_type', $transfer->getMorphClass())->where('reference_id', $transfer->id)
                ->where('type', MoneyTransactionType::BoxTransferOut)->firstOrFail();
            $this->ledger->reverse($out, $by, $reason, (string) Str::uuid());

            $transfer->forceFill(['status' => BoxTransferStatus::Cancelled, 'voided_by' => $by->id, 'voided_at' => now(), 'void_reason' => $reason])->save();

            return $transfer;
        });
    }

    private function authorize(User $by, ?int $branchId): void
    {
        if (! $this->scope->allows($by, 'cash_boxes.transfer', $branchId)) {
            throw new AuthorizationException(__('You are not allowed to move money for this box.'));
        }
    }
}
