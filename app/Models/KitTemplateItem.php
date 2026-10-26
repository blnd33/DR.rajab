<?php

namespace App\Models;

use App\Enums\KitItemKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One expected line of a kit type: an instrument type or an implant product (size).
 *
 * @property int $id
 * @property int $kit_type_id
 * @property KitItemKind $item_kind
 * @property ?int $instrument_type_id
 * @property ?int $product_id
 * @property int $expected_qty
 * @property bool $is_required
 * @property int $sort
 */
class KitTemplateItem extends Model
{
    protected $fillable = ['kit_type_id', 'item_kind', 'instrument_type_id', 'product_id', 'expected_qty', 'is_required', 'sort'];

    protected function casts(): array
    {
        return [
            'item_kind' => KitItemKind::class,
            'expected_qty' => 'integer',
            'is_required' => 'boolean',
            'sort' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (KitTemplateItem $item) {
            $item->item_kind = $item->product_id !== null ? KitItemKind::Product : KitItemKind::Instrument;
        });
    }

    /** @return BelongsTo<KitType, $this> */
    public function kitType(): BelongsTo
    {
        return $this->belongsTo(KitType::class);
    }

    /** @return BelongsTo<InstrumentType, $this> */
    public function instrumentType(): BelongsTo
    {
        return $this->belongsTo(InstrumentType::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function label(): string
    {
        return $this->product_id !== null
            ? trim($this->product->sku.' · '.$this->product->name.' '.($this->product->size ?? ''))
            : $this->instrumentType->name ?? '—';
    }
}
