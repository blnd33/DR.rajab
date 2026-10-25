<?php

namespace App\Models;

use App\Enums\TrackingType;
use App\Models\Concerns\HasCleanTranslations;
use App\Models\Concerns\TracksAuthor;
use App\Support\Database\Pg;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One master catalog shared by all branches (Module 3).
 *
 * @property int $id
 * @property string $sku
 * @property ?string $gtin
 * @property string $name
 * @property ?string $description
 * @property ?int $category_id
 * @property ?int $brand_id
 * @property int $uom_id
 * @property ?string $size
 * @property TrackingType $tracking_type
 * @property bool $is_implant
 * @property bool $requires_expiry
 * @property ?string $purchase_price
 * @property ?string $purchase_currency
 * @property ?string $selling_price
 * @property ?string $selling_currency
 * @property int $min_stock_default
 * @property ?string $photo_path
 * @property bool $is_active
 */
class Product extends Model
{
    use HasCleanTranslations;

    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use LogsActivity;
    use TracksAuthor;

    /** @var list<string> */
    public array $translatable = ['name', 'description'];

    protected $fillable = [
        'sku',
        'gtin',
        'name',
        'description',
        'category_id',
        'brand_id',
        'uom_id',
        'size',
        'tracking_type',
        'is_implant',
        'requires_expiry',
        'purchase_price',
        'purchase_currency',
        'selling_price',
        'selling_currency',
        'min_stock_default',
        'photo_path',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'tracking_type' => TrackingType::class,
            'is_implant' => 'boolean',
            'requires_expiry' => 'boolean',
            'purchase_price' => 'decimal:4',
            'selling_price' => 'decimal:4',
            'min_stock_default' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Product $product) {
            // Codes are stored exactly as printed, minus surrounding spaces.
            $product->sku = trim($product->sku);
            $product->gtin = filled($product->gtin) ? trim($product->gtin) : null;
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('catalog')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function activityReferenceCode(): string
    {
        return $this->sku;
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<Uom, $this> */
    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class);
    }

    /** @return HasMany<ProductBarcode, $this> */
    public function barcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class);
    }

    /** @return HasMany<BranchProductSetting, $this> */
    public function branchSettings(): HasMany
    {
        return $this->hasMany(BranchProductSetting::class);
    }

    /** @return HasMany<StockUnit, $this> */
    public function stockUnits(): HasMany
    {
        return $this->hasMany(StockUnit::class);
    }

    /** @return HasMany<StockBatch, $this> */
    public function stockBatches(): HasMany
    {
        return $this->hasMany(StockBatch::class);
    }

    /** @return HasMany<StockOnHand, $this> */
    public function onHand(): HasMany
    {
        return $this->hasMany(StockOnHand::class);
    }

    /** @param Builder<Product> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * SKU, GTIN, any extra barcode, or the name in any language.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        $query->where(function (Builder $query) use ($term, $like) {
            $query->where('sku', Pg::isPostgres() ? 'ilike' : 'like', $like)
                ->orWhere('gtin', $term)
                ->orWhereRaw(Pg::jsonContainsSql('name'), [$like])
                ->orWhereHas('barcodes', fn (Builder $q) => $q->where('barcode', $term));
        });
    }

    public function isSerialTracked(): bool
    {
        return $this->tracking_type === TrackingType::Serial;
    }

    /**
     * "KN-TIB-5 — Tibial tray (Size 5)"
     */
    public function label(): string
    {
        $size = filled($this->size) ? " ({$this->size})" : '';

        return "{$this->sku} — {$this->name}{$size}";
    }

    public function minStockFor(Branch|int $branch): int
    {
        $branchId = $branch instanceof Branch ? $branch->id : $branch;

        $setting = $this->relationLoaded('branchSettings')
            ? $this->branchSettings->firstWhere('branch_id', $branchId)
            : $this->branchSettings()->where('branch_id', $branchId)->first();

        return $setting->min_stock ?? $this->min_stock_default;
    }
}
