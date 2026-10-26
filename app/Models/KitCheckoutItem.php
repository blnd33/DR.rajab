<?php

namespace App\Models;

use App\Enums\CheckoutItemKind;
use App\Enums\CheckoutItemOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of a kit checkout: what left with the kit and how it was reconciled.
 *
 * @property int $id
 * @property int $kit_checkout_id
 * @property CheckoutItemKind $item_kind
 * @property ?int $instrument_id
 * @property ?int $stock_unit_id
 * @property ?int $stock_batch_id
 * @property ?int $product_id
 * @property int $qty_out
 * @property int $qty_returned
 * @property int $qty_used
 * @property int $qty_unaccounted
 * @property CheckoutItemOutcome $outcome
 * @property ?int $surgery_item_id
 * @property ?int $issue_id
 * @property ?Carbon $resolved_at
 * @property ?string $note
 */
class KitCheckoutItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'item_kind' => CheckoutItemKind::class,
            'outcome' => CheckoutItemOutcome::class,
            'qty_out' => 'integer',
            'qty_returned' => 'integer',
            'qty_used' => 'integer',
            'qty_unaccounted' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<KitCheckout, $this> */
    public function checkout(): BelongsTo
    {
        return $this->belongsTo(KitCheckout::class, 'kit_checkout_id');
    }

    /** @return BelongsTo<Instrument, $this> */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }

    /** @return BelongsTo<StockUnit, $this> */
    public function stockUnit(): BelongsTo
    {
        return $this->belongsTo(StockUnit::class);
    }

    /** @return BelongsTo<StockBatch, $this> */
    public function stockBatch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<SurgeryItem, $this> */
    public function surgeryItem(): BelongsTo
    {
        return $this->belongsTo(SurgeryItem::class);
    }

    /** @return BelongsTo<Issue, $this> */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function code(): string
    {
        return match ($this->item_kind) {
            CheckoutItemKind::Instrument => $this->instrument->code ?? '—',
            CheckoutItemKind::Unit => $this->stockUnit->serial ?? '—',
            CheckoutItemKind::Batch => $this->stockBatch->lot ?? '—',
        };
    }

    public function name(): string
    {
        return $this->item_kind === CheckoutItemKind::Instrument
            ? ($this->instrument->type->name ?? '—')
            : trim(($this->product->name ?? '—').' '.($this->product->size ?? ''));
    }

    public function outstanding(): int
    {
        return max(0, $this->qty_out - $this->qty_returned - $this->qty_used);
    }
}
