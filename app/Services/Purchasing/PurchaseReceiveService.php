<?php

namespace App\Services\Purchasing;

use App\Enums\MovementType;
use App\Enums\PurchaseStatus;
use App\Enums\ReceiptMethod;
use App\Enums\ReceivingStatus;
use App\Exceptions\Domain\MoneyRuleViolation;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReceipt;
use App\Models\User;
use App\Services\Barcode\ScanResolver;
use App\Services\Numbering\DocumentNumberGenerator;
use App\Services\Permissions\BranchScopeResolver;
use App\Services\Stock\Data\ReceiveLine;
use App\Services\Stock\StockMovementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Receive a confirmed purchase into stock, in one or more deliveries (P4-7). Each line keeps
 * its true cost and its purchase line, so landed costs later reach exactly its quantity.
 */
class PurchaseReceiveService
{
    public function __construct(
        private readonly StockMovementService $stock,
        private readonly BranchScopeResolver $scope,
        private readonly DocumentNumberGenerator $numbers,
        private readonly ScanResolver $scanner,
    ) {}

    /**
     * @param  list<array{purchase_item_id: int, serial?: ?string, lot?: ?string, expiry_date?: ?string, quantity?: int|null, location_id?: ?int}>  $lines
     */
    public function receive(Purchase $purchase, array $lines, User $by, ReceiptMethod $method = ReceiptMethod::Manual, ?string $notes = null): PurchaseReceipt
    {
        if (! $this->scope->allows($by, 'purchases.receive', $purchase->branch_id)) {
            throw new AuthorizationException(__('You are not allowed to receive purchases in this station.'));
        }

        if ($lines === []) {
            throw new MoneyRuleViolation(__('Add at least one item.'), 'lines');
        }

        return DB::transaction(function () use ($purchase, $lines, $by, $method, $notes) {
            /** @var Purchase $purchase */
            $purchase = Purchase::query()->with(['items.product', 'branch'])->lockForUpdate()->findOrFail($purchase->id);

            if ($purchase->status !== PurchaseStatus::Confirmed) {
                throw new MoneyRuleViolation(__('Confirm the purchase before receiving it.'));
            }

            $receipt = PurchaseReceipt::query()->create([
                'number' => $this->numbers->next('GRN', $purchase->branch),
                'purchase_id' => $purchase->id,
                'branch_id' => $purchase->branch_id,
                'method' => $method,
                'received_by' => $by->id,
                'received_at' => now(),
                'notes' => $notes,
            ]);

            $receiving = [];

            foreach ($lines as $line) {
                /** @var PurchaseItem|null $item */
                $item = $purchase->items->firstWhere('id', (int) $line['purchase_item_id']);

                if ($item === null) {
                    throw new MoneyRuleViolation(__('This line is not on purchase :number.', ['number' => $purchase->number]), 'lines');
                }

                $quantity = $item->product->isSerialTracked() ? 1 : max(1, (int) ($line['quantity'] ?? 1));
                $receiving[$item->id] = ($receiving[$item->id] ?? 0) + $quantity;

                if ($item->quantity_received + $receiving[$item->id] > $item->quantity) {
                    throw new MoneyRuleViolation(__('More :sku than ordered (:qty).', ['sku' => $item->product->sku, 'qty' => $item->quantity]), 'lines');
                }

                $result = $this->stock->receive($purchase->branch, [ReceiveLine::fromArray([
                    'product_id' => $item->product_id,
                    'quantity' => $quantity,
                    'serial' => $line['serial'] ?? null,
                    'lot' => ($line['lot'] ?? null) ?: $item->expected_lot,
                    'expiry_date' => ($line['expiry_date'] ?? null) ?: $item->expected_expiry?->toDateString(),
                    'unit_cost' => $item->true_unit_cost,
                    'cost_currency' => $purchase->currency,
                    'supplier_id' => $purchase->supplier_id,
                    'location_id' => $line['location_id'] ?? null,
                    'purchase_item_id' => $item->id,
                    'cost_fx_snapshot_id' => $purchase->fx_snapshot_id,
                ])], $by, MovementType::PurchaseReceipt, $receipt, __('Purchase :number', ['number' => $purchase->number]), 'purchases.receive');

                $unit = $result->units[0] ?? null;
                $batch = $result->batches[0] ?? null;

                $receipt->items()->create([
                    'purchase_item_id' => $item->id,
                    'stock_unit_id' => $unit?->id,
                    'stock_batch_id' => $batch?->id,
                    'serial' => $unit?->serial,
                    'lot' => $unit !== null ? $unit->lot : $batch?->lot,
                    'expiry_date' => ($unit ?? $batch)?->expiry_date?->toDateString(),
                    'quantity' => $quantity,
                ]);
            }

            foreach ($receiving as $itemId => $quantity) {
                PurchaseItem::query()->whereKey($itemId)->increment('quantity_received', $quantity);
            }

            $this->refreshReceiving($purchase);

            return $receipt;
        });
    }

    /**
     * Turn a scanned code into a receiving line of this purchase (product must be on it).
     *
     * @return array{purchase_item_id: int, serial: ?string, lot: ?string, expiry_date: ?string, quantity: int, label: string}
     */
    public function lineFromScan(Purchase $purchase, string $raw): array
    {
        $parsed = $this->scanner->parse($raw);
        $result = $this->scanner->resolve($raw, user: null, branchId: $purchase->branch_id);
        $product = $result->product;

        if ($product === null) {
            throw new MoneyRuleViolation(__('Code not recognised.'));
        }

        /** @var PurchaseItem|null $item */
        $item = $purchase->items()->where('product_id', $product->id)->whereColumn('quantity_received', '<', 'quantity')->first();

        if ($item === null) {
            throw new MoneyRuleViolation(__(':sku is not on this purchase (or is fully received).', ['sku' => $product->sku]));
        }

        // Only a real serial (GS1 AI 21, maker formats) — a product barcode or SKU is never a serial.
        $serial = $parsed->serial;

        if ($product->isSerialTracked() && blank($serial)) {
            throw new MoneyRuleViolation(__(':sku needs its serial number — scan the label with the serial or type the line.', ['sku' => $product->sku]));
        }

        return [
            'purchase_item_id' => $item->id,
            'serial' => $serial,
            'lot' => $parsed?->lot,
            'expiry_date' => $parsed?->expiryDate,
            'quantity' => 1,
            'label' => $product->sku,
        ];
    }

    private function refreshReceiving(Purchase $purchase): void
    {
        $items = $purchase->items()->get();
        $ordered = $items->sum('quantity');
        $received = $items->sum('quantity_received');

        $purchase->forceFill(['receiving_status' => match (true) {
            $received === 0 => ReceivingStatus::NotReceived,
            $received >= $ordered => ReceivingStatus::Received,
            default => ReceivingStatus::PartiallyReceived,
        }])->save();
    }
}
