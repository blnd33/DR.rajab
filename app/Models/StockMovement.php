<?php

namespace App\Models;

use App\Enums\LocationType;
use App\Enums\MovementType;
use App\Enums\StockStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * THE stock ledger (append-only — the database rejects UPDATE/DELETE).
 * Written only by StockMovementService.
 *
 * @property int $id
 * @property string $movement_group
 * @property MovementType $type
 * @property int $product_id
 * @property ?int $stock_unit_id
 * @property ?int $from_batch_id
 * @property ?int $to_batch_id
 * @property int $quantity
 * @property ?int $from_branch_id
 * @property ?int $to_branch_id
 * @property ?StockStatus $from_status
 * @property ?StockStatus $to_status
 * @property ?LocationType $from_location_type
 * @property ?LocationType $to_location_type
 * @property ?int $from_kit_id
 * @property ?int $to_kit_id
 * @property ?int $customer_id
 * @property ?string $unit_cost
 * @property ?string $cost_currency
 * @property ?string $reason
 * @property ?array<string, mixed> $details
 * @property bool $is_override
 * @property ?string $override_reason
 * @property ?int $user_id
 * @property Carbon $occurred_at
 */
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => MovementType::class,
            'from_status' => StockStatus::class,
            'to_status' => StockStatus::class,
            'from_location_type' => LocationType::class,
            'to_location_type' => LocationType::class,
            'quantity' => 'integer',
            'unit_cost' => 'decimal:4',
            'details' => 'array',
            'is_override' => 'boolean',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Same rule as the database trigger, so the mistake surfaces with a clear message.
        static::updating(fn () => throw new LogicException('Stock movements are append-only. Record a correcting movement instead.'));
        static::deleting(fn () => throw new LogicException('Stock movements are append-only. Record a correcting movement instead.'));
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

    /** @return BelongsTo<Branch, $this> */
    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphTo<Model, $this> */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Movements touching any of these branches (either side).
     *
     * @param  Builder<StockMovement>  $query
     * @param  list<int>|null  $branchIds  null = all
     */
    public function scopeForBranches(Builder $query, ?array $branchIds): void
    {
        if ($branchIds === null) {
            return;
        }

        if ($branchIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(fn (Builder $q) => $q->whereIn('from_branch_id', $branchIds)->orWhereIn('to_branch_id', $branchIds));
    }

    /**
     * Branch the movement happened in (receiving side first).
     */
    public function branchId(): ?int
    {
        return $this->to_branch_id ?? $this->from_branch_id;
    }
}
