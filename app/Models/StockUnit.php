<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\LocationType;
use App\Enums\StockStatus;
use App\Models\Concerns\HasExpiry;
use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One physical serial-tracked unit (implant). Status, branch and location change
 * only through StockMovementService, which writes the ledger row at the same time.
 *
 * @property int $id
 * @property int $product_id
 * @property string $serial
 * @property ?string $lot
 * @property ?Carbon $expiry_date
 * @property ?Carbon $production_date
 * @property int $branch_id
 * @property StockStatus $status
 * @property LocationType $location_type
 * @property ?int $branch_location_id
 * @property string $base_unit_cost
 * @property string $landed_unit_cost
 * @property string $unit_cost
 * @property string $cost_currency
 * @property ?int $supplier_id
 * @property Carbon $received_at
 * @property ?int $last_expiry_level_id
 * @property int $lock_version
 */
class StockUnit extends Model
{
    use HasExpiry;
    use LogsActivity;
    use TracksAuthor;

    // Mass assignment is only used by StockMovementService.
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => StockStatus::class,
            'location_type' => LocationType::class,
            'expiry_date' => DateOnly::class,
            'production_date' => DateOnly::class,
            'base_unit_cost' => 'decimal:4',
            'landed_unit_cost' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'received_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('stock')
            ->logOnly(['serial', 'lot', 'expiry_date', 'production_date', 'branch_id', 'status', 'branch_location_id', 'base_unit_cost', 'landed_unit_cost', 'unit_cost', 'cost_currency', 'supplier_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function activityReferenceCode(): string
    {
        return $this->serial;
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

    /** @return HasMany<StockMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** @param Builder<StockUnit> $query */
    public function scopeOnHand(Builder $query): void
    {
        $query->whereIn('status', StockStatus::onHand());
    }

    /** @param Builder<StockUnit> $query */
    public function scopeAvailable(Builder $query): void
    {
        $query->where('status', StockStatus::InStock);
    }

    /**
     * "Shelf A-1", "In transit"…
     */
    public function locationLabel(): string
    {
        if ($this->location_type === LocationType::Shelf) {
            return $this->location !== null
                ? __('Shelf :code', ['code' => $this->location->label()])
                : __('Shelf');
        }

        return $this->location_type->getLabel();
    }
}
