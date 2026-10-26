<?php

namespace App\Services\Expenses;

use App\Enums\CommissionBasis;
use App\Enums\CommissionCalc;
use App\Enums\CommissionStatus;
use App\Enums\MoneyTransactionType;
use App\Enums\SurgeryDoctorRole;
use App\Exceptions\Domain\MoneyRuleViolation;
use App\Models\CashBox;
use App\Models\CommissionEntry;
use App\Models\CommissionRule;
use App\Models\Surgery;
use App\Models\User;
use App\Services\Money\FxService;
use App\Services\Money\MoneyLedgerService;
use App\Services\Settings\SettingsRepository;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Doctor commissions (Module 22, P4-15) — only while the commissions feature flag is on.
 * Accrued when a surgery completes; approved; paid from a box.
 */
class CommissionService
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly FxService $fx,
        private readonly MoneyLedgerService $ledger,
    ) {}

    public function enabled(): bool
    {
        return $this->settings->bool('features.commissions');
    }

    /**
     * @return Collection<int, CommissionEntry>
     */
    public function accrueFor(Surgery $surgery): Collection
    {
        $entries = collect();

        if (! $this->enabled()) {
            return $entries;
        }

        $surgery->loadMissing(['doctors', 'liveItems.product']);
        $lead = $surgery->doctors->first(fn (User $d) => $d->getRelationValue('pivot')?->getAttribute('role') === SurgeryDoctorRole::Lead->value) ?? $surgery->doctors->first();

        if ($lead === null) {
            return $entries;
        }

        $snapshot = $this->fx->currentOrFail();
        $converter = $this->fx->converter($snapshot);
        $date = $surgery->surgery_date->toDateString();

        $rules = CommissionRule::query()->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('doctor_id')->orWhere('doctor_id', $lead->id))
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $date))
            ->get();

        foreach ($rules as $rule) {
            $currency = $rule->currency ?? 'USD';

            if ($rule->basis === CommissionBasis::PerSurgery) {
                if ($rule->surgery_type_id !== null && $rule->surgery_type_id !== $surgery->surgery_type_id) {
                    continue;
                }

                $value = $surgery->liveItems->reduce(fn (BigDecimal $sum, $item) => $sum->plus(
                    $item->product->selling_price !== null ? $converter->convert((string) BigDecimal::of((string) $item->product->selling_price)->multipliedBy($item->quantity), $item->product->selling_currency ?? 'USD', $currency) : '0'
                ), BigDecimal::zero());

                $amount = $rule->calc_type === CommissionCalc::Fixed ? BigDecimal::of($rule->value) : $value->multipliedBy($rule->value)->dividedBy(100, 4, RoundingMode::HalfUp);
                $entries->push($this->entry($lead, $surgery, null, $rule, $amount, $currency, $snapshot->id));

                continue;
            }

            foreach ($surgery->liveItems as $item) {
                $matches = ($rule->product_id !== null && $rule->product_id === $item->product_id)
                    || ($rule->category_id !== null && $rule->category_id === $item->product->category_id);

                if (! $matches) {
                    continue;
                }

                $price = $item->product->selling_price !== null
                    ? BigDecimal::of($converter->convert((string) $item->product->selling_price, $item->product->selling_currency ?? 'USD', $currency))
                    : BigDecimal::zero();
                $amount = $rule->calc_type === CommissionCalc::Fixed
                    ? BigDecimal::of($rule->value)->multipliedBy($item->quantity)
                    : $price->multipliedBy($item->quantity)->multipliedBy($rule->value)->dividedBy(100, 4, RoundingMode::HalfUp);

                $entries->push($this->entry($lead, $surgery, $item->id, $rule, $amount, $currency, $snapshot->id));
            }
        }

        return $entries->filter();
    }

    public function approve(CommissionEntry $entry, User $by): CommissionEntry
    {
        $this->authorize($by);

        if ($entry->status !== CommissionStatus::Accrued) {
            throw new MoneyRuleViolation(__('Only an earned commission can be approved.'));
        }

        $entry->forceFill(['status' => CommissionStatus::Approved, 'approved_by' => $by->id, 'approved_at' => now()])->save();

        return $entry;
    }

    /**
     * Pay approved commissions of one doctor in the box currency.
     *
     * @param  list<int>  $entryIds
     */
    public function pay(array $entryIds, CashBox $box, User $by): string
    {
        $this->authorize($by);

        return DB::transaction(function () use ($entryIds, $box, $by) {
            $entries = CommissionEntry::query()->whereIn('id', $entryIds)->lockForUpdate()->get();

            if ($entries->isEmpty() || $entries->contains(fn ($e) => $e->status !== CommissionStatus::Approved || $e->currency !== $box->currency)) {
                throw new MoneyRuleViolation(__('Pick approved commissions in the box currency.'));
            }

            $total = $entries->reduce(fn (BigDecimal $sum, $e) => $sum->plus($e->amount), BigDecimal::zero());
            $transaction = $this->ledger->post($box, MoneyTransactionType::CommissionPayout, (string) $total->negated(), $by, $entries->first(), (string) Str::uuid(),
                __('Commission for :doctor', ['doctor' => $entries->first()->doctor->name]));

            CommissionEntry::query()->whereIn('id', $entries->pluck('id'))->update(['status' => CommissionStatus::Paid->value, 'money_transaction_id' => $transaction->id]);

            return (string) $total;
        });
    }

    private function entry(User $doctor, Surgery $surgery, ?int $itemId, CommissionRule $rule, BigDecimal $amount, string $currency, int $snapshotId): ?CommissionEntry
    {
        if (! $amount->isPositive()) {
            return null;
        }

        return CommissionEntry::query()->create([
            'doctor_id' => $doctor->id,
            'surgery_id' => $surgery->id,
            'surgery_item_id' => $itemId,
            'commission_rule_id' => $rule->id,
            'amount' => (string) $amount->toScale(4, RoundingMode::HalfUp),
            'currency' => $currency,
            'fx_snapshot_id' => $snapshotId,
            'status' => CommissionStatus::Accrued,
        ]);
    }

    private function authorize(User $by): void
    {
        if (! $this->enabled() || ! $by->can('commissions.manage')) {
            throw new AuthorizationException(__('Commissions are not available.'));
        }
    }
}
