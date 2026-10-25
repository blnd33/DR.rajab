<?php

namespace App\Models;

use App\Enums\BarcodeSymbology;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Extra code that identifies a product (supplier code, EAN, our own label).
 *
 * @property int $id
 * @property int $product_id
 * @property string $barcode
 * @property BarcodeSymbology $symbology
 */
class ProductBarcode extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['product_id', 'barcode', 'symbology'];

    protected function casts(): array
    {
        return ['symbology' => BarcodeSymbology::class];
    }

    protected static function booted(): void
    {
        static::creating(function (ProductBarcode $barcode) {
            $barcode->barcode = trim($barcode->barcode);
            $barcode->created_by ??= Auth::id();
        });
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
