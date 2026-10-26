<?php

namespace App\Services\Purchasing;

use App\Enums\CreditNoteDirection;
use App\Enums\CreditNoteStatus;
use App\Enums\PartyEntryType;
use App\Enums\SupplierReturnStatus;
use App\Exceptions\Domain\MoneyRuleViolation;
use App\Models\CreditNote;
use App\Models\SupplierReturn;
use App\Models\User;
use App\Services\Money\FxService;
use App\Services\Money\PartyLedgerService;
use App\Services\Numbering\DocumentNumberGenerator;
use App\Services\Permissions\BranchScopeResolver;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Credit notes (P4-13): the supplier's credit for items returned to them (Phase 2 supplier
 * returns) lowers what we owe them on their statement.
 */
class CreditNoteService
{
    public function __construct(
        private readonly PartyLedgerService $party,
        private readonly FxService $fx,
        private readonly BranchScopeResolver $scope,
        private readonly DocumentNumberGenerator $numbers,
    ) {}

    public function fromSupplierReturn(SupplierReturn $return, User $by, ?string $amount = null, ?string $reference = null, ?string $note = null): CreditNote
    {
        if (! $this->scope->allows($by, 'returns.supplier', $return->branch_id) || ! $by->can('supplier_payments.create')) {
            throw new AuthorizationException(__('You are not allowed to record supplier credit.'));
        }

        return DB::transaction(function () use ($return, $by, $amount, $reference, $note) {
            /** @var SupplierReturn $return */
            $return = SupplierReturn::query()->with(['supplier', 'branch'])->lockForUpdate()->findOrFail($return->id);

            if ($return->status !== SupplierReturnStatus::Sent) {
                throw new MoneyRuleViolation(__('Credit can be recorded for a sent return only.'));
            }

            $value = BigDecimal::of($amount ?? (string) $return->total_credit)->toScale(4, RoundingMode::HalfUp);

            if (! $value->isPositive() || $return->currency === null) {
                throw new MoneyRuleViolation(__('The credit amount must be above zero.'), 'amount');
            }

            $snapshot = $this->fx->currentOrFail();
            $credit = new CreditNote([
                'number' => $this->numbers->next('CRN', $return->branch),
                'direction' => CreditNoteDirection::FromSupplier,
                'currency' => $return->currency,
                'fx_snapshot_id' => $snapshot->id,
                'amount' => (string) $value,
                'status' => CreditNoteStatus::Applied,
                'reference' => $reference,
                'note' => $note,
                'created_by' => $by->id,
            ]);
            $credit->party()->associate($return->supplier);
            $credit->source()->associate($return);
            $credit->save();

            $this->party->record($return->supplier, PartyEntryType::CreditNote, $return->currency, (string) $value->negated(), $credit,
                __('Credit :number for return :return', ['number' => $credit->number, 'return' => $return->number]), $by, $snapshot);

            $return->forceFill(['status' => SupplierReturnStatus::Credited, 'credit_note_id' => $credit->id, 'fx_snapshot_id' => $return->fx_snapshot_id ?? $snapshot->id])->save();

            return $credit;
        });
    }
}
