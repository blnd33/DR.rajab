<?php

namespace App\Services\Movement;

use App\Enums\CustomerReturnStatus;
use App\Enums\LocationType;
use App\Enums\MovementType;
use App\Enums\ReturnCondition;
use App\Enums\ReturnReason;
use App\Enums\StockStatus;
use App\Enums\SupplierReturnReason;
use App\Enums\SupplierReturnStatus;
use App\Enums\WriteOffReason;
use App\Exceptions\Domain\StockRuleViolation;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerReturn;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\StockUnit;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\User;
use App\Services\Numbering\DocumentNumberGenerator;
use App\Services\Permissions\BranchScopeResolver;
use App\Services\Stock\Data\ReceiveLine;
use App\Services\Stock\StockMovementService;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Returns (Module 14).
 *  • Customer → our stock: a unit that left us (at hospital, used, sold) or a lot quantity
 *    comes back; good → shelf, damaged → shelf + pending write-off.
 *  • Our stock → supplier: draft → sent (units "returned to supplier", lots removed).
 *    The credit note and supplier balance arrive with Phase 4.
 */
class ReturnService
{
    public function __construct(
        private readonly StockMovementService $stock,
        private readonly BranchScopeResolver $scope,
        private readonly DocumentNumberGenerator $numbers,
        private readonly WriteOffService $writeOffs,
    ) {}

    /**
     * @param  list<array{stock_unit_id?: ?int, product_id?: ?int, lot?: ?string, expiry_date?: ?string, quantity?: ?int, unit_cost?: ?string, cost_currency?: ?string, reason: string, condition: string}>  $items
     */
    public function receiveFromCustomer(Branch $branch, array $items, User $by, ?Customer $customer = null, ?string $notes = null): CustomerReturn
    {
        $this->authorize($by, 'returns.customer', $branch);

        if ($items === []) {
            throw new StockRuleViolation(__('Add at least one item.'));
        }

        return DB::transaction(function () use ($branch, $items, $by, $customer, $notes) {
            $return = CustomerReturn::query()->create([
                'number' => $this->numbers->next('RET', $branch),
                'branch_id' => $branch->id,
                'customer_id' => $customer?->id,
                'status' => CustomerReturnStatus::Completed,
                'received_by' => $by->id,
                'received_at' => now(),
                'notes' => $notes,
                'created_by' => $by->id,
            ]);

            $group = (string) Str::uuid();
            $damaged = [];

            foreach ($items as $line) {
                $reason = ReturnReason::from($line['reason']);
                $condition = ReturnCondition::from($line['condition']);
                $note = __('Customer return :number: :reason', ['number' => $return->number, 'reason' => $reason->getLabel()]);

                if (filled($line['stock_unit_id'] ?? null)) {
                    $unit = StockUnit::query()->findOrFail($line['stock_unit_id']);
                    $previous = $unit->status;
                    $origin = StockMovement::query()->where('stock_unit_id', $unit->id)->latest('occurred_at')->latest('id')->first();

                    $this->stock->restockUnit($unit, $branch, MovementType::CustomerReturn, $by, $return, $group, 'returns.customer', $note);

                    $item = $return->items()->create([
                        'product_id' => $unit->product_id,
                        'stock_unit_id' => $unit->id,
                        'quantity' => 1,
                        'lot' => $unit->lot,
                        'expiry_date' => $unit->expiry_date,
                        'previous_status' => $previous,
                        'origin_type' => $origin?->reference_type,
                        'origin_id' => $origin?->reference_id,
                        'reason' => $reason,
                        'condition' => $condition,
                        'outcome' => $condition === ReturnCondition::Good ? 'restocked' : 'to_write_off',
                    ]);

                    if ($condition === ReturnCondition::Damaged) {
                        $damaged[] = ['item' => $item, 'line' => ['stock_unit_id' => $unit->id]];
                    }

                    continue;
                }

                $product = Product::query()->findOrFail($line['product_id'] ?? 0);

                if ($product->isSerialTracked()) {
                    throw new StockRuleViolation(__(':sku is serial-tracked — scan the serial number.', ['sku' => $product->sku]), 'serial');
                }

                $result = $this->stock->receive($branch, [ReceiveLine::fromArray([
                    'product_id' => $product->id,
                    'quantity' => (int) ($line['quantity'] ?? 1),
                    'lot' => $line['lot'] ?? null,
                    'expiry_date' => $line['expiry_date'] ?? null,
                    'unit_cost' => $line['unit_cost'] ?? $product->purchase_price ?? '0',
                    'cost_currency' => $line['cost_currency'] ?? $product->purchase_currency ?? 'USD',
                ])], $by, MovementType::CustomerReturn, $return, $note, 'returns.customer');

                $batch = $result->batches[0];
                $item = $return->items()->create([
                    'product_id' => $product->id,
                    'stock_batch_id' => $batch->id,
                    'quantity' => (int) ($line['quantity'] ?? 1),
                    'lot' => $batch->lot,
                    'expiry_date' => $batch->expiry_date,
                    'reason' => $reason,
                    'condition' => $condition,
                    'outcome' => $condition === ReturnCondition::Good ? 'restocked' : 'to_write_off',
                ]);

                if ($condition === ReturnCondition::Damaged) {
                    $damaged[] = ['item' => $item, 'line' => ['stock_batch_id' => $batch->id, 'quantity' => $item->quantity]];
                }
            }

            if ($damaged !== []) {
                $writeOff = $this->writeOffs->report(
                    $branch,
                    WriteOffReason::Damaged,
                    array_map(fn ($d) => $d['line'], $damaged),
                    $by,
                    __('Damaged items from customer return :number', ['number' => $return->number]),
                    source: $return,
                    permission: 'returns.customer',
                );

                foreach ($damaged as $d) {
                    $d['item']->update(['write_off_id' => $writeOff->id]);
                }
            }

            return $return;
        });
    }

    /**
     * @param  list<array{stock_unit_id?: ?int, stock_batch_id?: ?int, quantity?: ?int, reason: string, unit_credit?: ?string}>  $items
     */
    public function createSupplierReturn(Supplier $supplier, Branch $branch, array $items, User $by, ?string $notes = null): SupplierReturn
    {
        $this->authorize($by, 'returns.supplier', $branch);

        if ($items === []) {
            throw new StockRuleViolation(__('Add at least one item.'));
        }

        return DB::transaction(function () use ($supplier, $branch, $items, $by, $notes) {
            $return = SupplierReturn::query()->create([
                'number' => $this->numbers->next('SRT', $branch),
                'supplier_id' => $supplier->id,
                'branch_id' => $branch->id,
                'status' => SupplierReturnStatus::Draft,
                'notes' => $notes,
                'created_by' => $by->id,
            ]);

            $currencies = [];
            $total = BigDecimal::zero();

            foreach ($items as $line) {
                $reason = SupplierReturnReason::from($line['reason']);

                if (filled($line['stock_unit_id'] ?? null)) {
                    $unit = StockUnit::query()->findOrFail($line['stock_unit_id']);

                    if ($unit->branch_id !== $branch->id || $unit->status !== StockStatus::InStock) {
                        throw new StockRuleViolation(__('Unit :serial is not on a shelf in :branch.', ['serial' => $unit->serial, 'branch' => $branch->code]), 'serial');
                    }

                    $credit = filled($line['unit_credit'] ?? null) ? (string) $line['unit_credit'] : $unit->unit_cost;
                    $return->items()->create([
                        'product_id' => $unit->product_id, 'stock_unit_id' => $unit->id, 'quantity' => 1,
                        'reason' => $reason, 'unit_credit' => $credit, 'currency' => $unit->cost_currency,
                    ]);
                    $currencies[$unit->cost_currency] = true;
                    $total = $total->plus($credit);

                    continue;
                }

                $batch = StockBatch::query()->findOrFail($line['stock_batch_id'] ?? 0);
                $quantity = (int) ($line['quantity'] ?? 0);

                if ($batch->branch_id !== $branch->id || $batch->location_type !== LocationType::Shelf || $quantity < 1 || $quantity > $batch->quantity) {
                    throw new StockRuleViolation(__('Only :available of lot :lot are on the shelf (asked: :asked).', [
                        'available' => $batch->quantity, 'lot' => $batch->lot ?? '—', 'asked' => $quantity,
                    ]), 'quantity');
                }

                $credit = filled($line['unit_credit'] ?? null) ? (string) $line['unit_credit'] : $batch->unit_cost;
                $return->items()->create([
                    'product_id' => $batch->product_id, 'stock_batch_id' => $batch->id, 'quantity' => $quantity,
                    'reason' => $reason, 'unit_credit' => $credit, 'currency' => $batch->cost_currency,
                ]);
                $currencies[$batch->cost_currency] = true;
                $total = $total->plus(BigDecimal::of($credit)->multipliedBy($quantity));
            }

            if (count($currencies) > 1) {
                throw new StockRuleViolation(__('One return per currency: these items were bought in :currencies.', ['currencies' => implode(', ', array_keys($currencies))]));
            }

            $return->forceFill(['currency' => array_key_first($currencies), 'total_credit' => (string) $total->toScale(4)])->save();

            return $return;
        });
    }

    public function sendSupplierReturn(SupplierReturn $return, User $by): SupplierReturn
    {
        $this->authorize($by, 'returns.supplier', $return->branch_id);

        return DB::transaction(function () use ($return, $by) {
            /** @var SupplierReturn $return */
            $return = SupplierReturn::query()->with(['items.stockUnit', 'items.stockBatch'])->lockForUpdate()->findOrFail($return->id);

            if ($return->status !== SupplierReturnStatus::Draft) {
                throw new StockRuleViolation(__('This return was already sent.'));
            }

            $group = (string) Str::uuid();
            $note = __('Returned to supplier (:number)', ['number' => $return->number]);

            foreach ($return->items as $item) {
                $item->stock_unit_id !== null
                    ? $this->stock->moveUnitOut($item->stockUnit, StockStatus::ReturnedToSupplier, MovementType::SupplierReturn, $by, $return, $group, 'returns.supplier', $note)
                    : $this->stock->removeFromBatch($item->stockBatch, $item->quantity, MovementType::SupplierReturn, $by, $return, $group, 'returns.supplier', $note);
            }

            $return->forceFill(['status' => SupplierReturnStatus::Sent, 'sent_by' => $by->id, 'sent_at' => now()])->save();

            return $return;
        });
    }

    public function cancelSupplierReturn(SupplierReturn $return, string $reason, User $by): SupplierReturn
    {
        $this->authorize($by, 'returns.supplier', $return->branch_id);

        if ($return->status !== SupplierReturnStatus::Draft) {
            throw new StockRuleViolation(__('A sent return cannot be cancelled; the supplier credit note settles it.'));
        }

        $return->forceFill(['status' => SupplierReturnStatus::Cancelled, 'voided_at' => now(), 'voided_by' => $by->id, 'void_reason' => $reason])->save();

        return $return;
    }

    private function authorize(User $by, string $permission, Branch|int $branch): void
    {
        $branchId = $branch instanceof Branch ? $branch->id : $branch;

        if (! $this->scope->allows($by, $permission, $branchId)) {
            throw new AuthorizationException(__('You are not allowed to do this in station :code.', ['code' => (string) Branch::query()->whereKey($branchId)->value('code')]));
        }
    }
}
