<?php

namespace App\Services\Purchasing;

use App\Enums\AllocationMethod;
use App\Enums\LandedCostStatus;
use App\Enums\LandedCostType;
use App\Enums\PartyEntryType;
use App\Enums\PurchaseStatus;
use App\Exceptions\Domain\MoneyRuleViolation;
use App\Models\LandedCost;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\StockBatch;
use App\Models\StockUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Money\FxService;
use App\Services\Money\PartyLedgerService;
use App\Services\Numbering\DocumentNumberGenerator;
use App\Services\Permissions\BranchScopeResolver;
use App\Services\Stock\StockMovementService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Landed costs (Module 7, P4-8): spread a shipping / customs / clearance / bank fee over a
 * purchase's lines (by value or quantity) → higher true cost of what is still in stock;
 * the share of quantities already used or sold is kept as a cost-of-sales adjustment.
 */
class LandedCostService
{
    public function __construct(
        private readonly StockMovementService $stock,
        private readonly FxService $fx,
        private readonly PartyLedgerService $party,
        private readonly BranchScopeResolver $scope,
        private readonly DocumentNumberGenerator $numbers,
    ) {}

    /**
     * @param  array{type: string, amount: string|int|float, currency: string, allocation_method?: string, payee_supplier_id?: ?int, note?: ?string}  $data
     */
    public function add(Purchase $purchase, array $data, User $by): LandedCost
    {
        if (! $this->scope->allows($by, 'purchases.landed_costs', $purchase->branch_id)) {
            throw new AuthorizationException(__('You are not allowed to add landed costs in this station.'));
        }

        $amount = BigDecimal::of((string) $data['amount'])->toScale(4, RoundingMode::HalfUp);

        if (! $amount->isPositive()) {
            throw new MoneyRuleViolation(__('The amount must be above zero.'), 'amount');
        }

        return DB::transaction(function () use ($purchase, $data, $by, $amount) {
            /** @var Purchase $purchase */
            $purchase = Purchase::query()->with(['items', 'branch'])->lockForUpdate()->findOrFail($purchase->id);

            if ($purchase->status !== PurchaseStatus::Confirmed) {
                throw new MoneyRuleViolation(__('Landed costs go on a confirmed purchase.'));
            }

            $snapshot = $this->fx->currentOrFail();
            $rate = $this->fx->converter($snapshot)->rate($data['currency'], $purchase->currency);
            $inPurchaseCurrency = BigDecimal::of($this->fx->converter($snapshot)->convert((string) $amount, $data['currency'], $purchase->currency));
            $method = AllocationMethod::from($data['allocation_method'] ?? AllocationMethod::Value->value);

            $cost = LandedCost::query()->create([
                'number' => $this->numbers->next('LDC', $purchase->branch),
                'purchase_id' => $purchase->id,
                'type' => LandedCostType::from($data['type']),
                'payee_supplier_id' => $data['payee_supplier_id'] ?? null,
                'amount' => (string) $amount,
                'currency' => $data['currency'],
                'fx_snapshot_id' => $snapshot->id,
                'allocation_method' => $method,
                'status' => LandedCostStatus::Allocated,
                'note' => $data['note'] ?? null,
                'created_by' => $by->id,
                'allocated_at' => now(),
            ]);

            $shares = $this->shares($purchase, $method);
            $group = (string) Str::uuid();
            $cogs = BigDecimal::zero();
            $remaining = $inPurchaseCurrency;
            $last = array_key_last($shares);

            foreach ($shares as $itemId => $share) {
                /** @var PurchaseItem $item */
                $item = $purchase->items->firstWhere('id', $itemId);
                $lineAmount = $itemId === $last ? $remaining : $inPurchaseCurrency->multipliedBy($share)->toScale(4, RoundingMode::HalfUp);
                $remaining = $remaining->minus($lineAmount);
                $perUnit = $lineAmount->dividedBy($item->quantity, 4, RoundingMode::HalfUp);

                [$onHand, $used] = $this->applyToStock($item, (string) $perUnit, $by, $cost, $group);
                $cogs = $cogs->plus($perUnit->multipliedBy($used));

                $cost->allocations()->create([
                    'purchase_item_id' => $item->id,
                    'amount' => (string) $lineAmount,
                    'per_unit_amount' => (string) $perUnit,
                    'rate_used' => $rate,
                    'quantity_on_hand' => $onHand,
                    'quantity_used' => $used,
                ]);

                $item->forceFill([
                    'landed_cost_per_unit' => (string) BigDecimal::of($item->landed_cost_per_unit)->plus($perUnit),
                    'true_unit_cost' => (string) BigDecimal::of($item->true_unit_cost)->plus($perUnit),
                ])->save();
            }

            $cost->forceFill(['cogs_amount' => (string) $cogs->toScale(4, RoundingMode::HalfUp)])->save();

            if ($cost->payee_supplier_id !== null) {
                $this->party->record(Supplier::query()->findOrFail($cost->payee_supplier_id), PartyEntryType::LandedCost, $cost->currency, (string) $amount, $cost,
                    __(':type for purchase :number', ['type' => $cost->type->getLabel(), 'number' => $purchase->number]), $by, $snapshot);
            }

            return $cost;
        });
    }

    /**
     * Raise the cost of this line's stock still on hand.
     *
     * @return array{0: int, 1: int} quantity on hand, quantity already gone (received − on hand)
     */
    private function applyToStock(PurchaseItem $item, string $perUnit, User $by, LandedCost $cost, string $group): array
    {
        if (BigDecimal::of($perUnit)->isZero()) {
            return [0, 0];
        }

        $reason = __('Landed cost :number', ['number' => $cost->number]);
        $onHand = 0;

        StockUnit::query()->where('purchase_item_id', $item->id)->get()
            ->filter(fn (StockUnit $u) => $u->status->isOnHand())
            ->each(function (StockUnit $unit) use ($perUnit, $by, $cost, $group, $reason, &$onHand) {
                $this->stock->addLandedCostToUnit($unit, $perUnit, $by, $cost, $group, $reason);
                $onHand++;
            });

        StockBatch::query()->where('purchase_item_id', $item->id)->where('quantity', '>', 0)->get()
            ->each(function (StockBatch $batch) use ($perUnit, $by, $cost, $group, $reason, &$onHand) {
                $onHand += $batch->quantity;
                $this->stock->addLandedCostToBatch($batch, $perUnit, $by, $cost, $group, $reason);
            });

        return [$onHand, max(0, $item->quantity_received - $onHand)];
    }

    /**
     * @return array<int, BigDecimal> item id => share (sums to 1)
     */
    private function shares(Purchase $purchase, AllocationMethod $method): array
    {
        $weights = $purchase->items->mapWithKeys(fn (PurchaseItem $i) => [
            $i->id => $method === AllocationMethod::Quantity ? BigDecimal::of($i->quantity) : BigDecimal::of($i->line_total),
        ]);
        $total = $weights->reduce(fn (BigDecimal $sum, BigDecimal $w) => $sum->plus($w), BigDecimal::zero());

        if ($total->isZero()) {
            $weights = $purchase->items->mapWithKeys(fn (PurchaseItem $i) => [$i->id => BigDecimal::of($i->quantity)]);
            $total = $weights->reduce(fn (BigDecimal $sum, BigDecimal $w) => $sum->plus($w), BigDecimal::zero());
        }

        return $weights->map(fn (BigDecimal $w) => $w->dividedBy($total, 12, RoundingMode::HalfUp))->all();
    }
}
