<?php

namespace App\Models;

use App\Enums\TransferLineResolution;
use App\Enums\TransferLineStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One unit or lot quantity on a transfer. Lots in transit keep their source bucket
 * (from_batch_id, cost layer) until received into to_batch_id.
 *
 * @property int $id
 * @property int $transfer_id
 * @property int $product_id
 * @property ?int $stock_unit_id
 * @property ?int $from_batch_id
 * @property ?int $to_batch_id
 * @property int $quantity_sent
 * @property ?int $quantity_received
 * @property TransferLineStatus $line_status
 * @property TransferLineResolution $resolution
 * @property ?string $override_reason
 */
class TransferItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity_sent' => 'integer',
            'quantity_received' => 'integer',
            'line_status' => TransferLineStatus::class,
            'resolution' => TransferLineResolution::class,
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Transfer, $this> */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<StockUnit, $this> */
    public function stockUnit(): BelongsTo
    {
        return $this->belongsTo(StockUnit::class);
    }

    /** @return BelongsTo<StockBatch, $this> */
    public function fromBatch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'from_batch_id');
    }

    /** @return BelongsTo<StockBatch, $this> */
    public function toBatch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'to_batch_id');
    }

    public function code(): string
    {
        return $this->stockUnit->serial ?? $this->fromBatch->lot ?? '—';
    }

    /**
     * Quantity still unaccounted at the receiver (missing units / short lots).
     */
    public function outstanding(): int
    {
        return max(0, $this->quantity_sent - (int) $this->quantity_received);
    }
}
