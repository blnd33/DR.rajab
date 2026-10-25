<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\LocationType;
use App\Models\Concerns\HasExpiry;
use App\Models\Concerns\TracksAuthor;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A lot-tracked bucket: quantity of one product, lot and expiry in one place at one cost.
 *
 * @property int $id
 * @property int $product_id
 * @property ?string $lot
 * @property ?Carbon $expiry_date
 * @property ?Carbon $production_date
 * @property int $branch_id
 * @property LocationType $location_type
 * @property ?int $branch_location_id
 * @property int $quantity
 * @property string $base_unit_cost
 * @property string $landed_unit_cost
 * @property string $unit_cost
 * @property string $cost_currency
 * @property ?int $supplier_id
 * @property ?int $last_expiry_level_id
 */
class StockBatch extends Model
{
    use HasExpiry;
    use LogsActivity;
    use TracksAuthor;

    // Mass assignment is only used by StockMovementService.
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'location_type' => LocationType::class,
            'expiry_date' => DateOnly::class,
            'production_date' => DateOnly::class,
            'quantity' => 'integer',
            'base_unit_cost' => 'decimal:4',
            'landed_unit_cost' => 'decimal:4',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('stock')
            ->logOnly(['lot', 'expiry_date', 'production_date', 'branch_id', 'quantity', 'branch_location_id', 'unit_cost', 'cost_currency', 'supplier_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function activityReferenceCode(): ?string
    {
        return $this->lot;
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<BranchLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(BranchLocation::class, 'branch_location_id');
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Ledger rows where this bucket was the source or the destination.
     *
     * @return Builder<StockMovement>
     */
    public function movements(): Builder
    {
        return StockMovement::query()->where(fn (Builder $q) => $q->where('from_batch_id', $this->id)->orWhere('to_batch_id', $this->id));
    }

    /** @param Builder<StockBatch> $query */
    public function scopeOnHand(Builder $query): void
    {
        $query->where('quantity', '>', 0);
    }

    /** @param Builder<StockBatch> $query */
    public function scopeAvailable(Builder $query): void
    {
        $query->where('quantity', '>', 0)->where('location_type', LocationType::Shelf);
    }

    public function locationLabel(): string
    {
        if ($this->location_type === LocationType::Shelf) {
            return $this->location !== null
                ? __('Shelf :code', ['code' => $this->location->label()])
                : __('Shelf');
        }

        return $this->location_type->getLabel();
    }

    /**
     * Value of this bucket in its cost currency (quantity × true unit cost).
     */
    public function value(): string
    {
        return (string) BigDecimal::of($this->unit_cost)->multipliedBy($this->quantity)->toScale(4);
    }

    /**
     * Sum of quantities in a query of buckets.
     *
     * @param  Builder<StockBatch>  $query
     */
    public static function sumQuantity(Builder $query): int
    {
        return (int) $query->sum(DB::raw('quantity'));
    }
}
