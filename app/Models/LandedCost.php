<?php

namespace App\Models;

use App\Enums\AllocationMethod;
use App\Enums\LandedCostStatus;
use App\Enums\LandedCostType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Shipping, customs, clearance, bank fees added to a purchase and spread over its lines.
 *
 * @property int $id
 * @property LandedCostType $type
 * @property string $amount
 * @property AllocationMethod $allocation_method
 * @property LandedCostStatus $status
 * @property string $cogs_amount
 * @property ?Carbon $allocated_at
 * @property ?Carbon $voided_at
 * @property string $number
 * @property int $purchase_id
 * @property ?int $payee_supplier_id
 * @property string $currency
 * @property ?string $note
 */
class LandedCost extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => LandedCostType::class,
            'amount' => 'decimal:4',
            'allocation_method' => AllocationMethod::class,
            'status' => LandedCostStatus::class,
            'cogs_amount' => 'decimal:4',
            'allocated_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function activityReferenceCode(): string
    {
        return (string) $this->getAttribute('number');
    }

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function payee(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'payee_supplier_id');
    }

    /** @return HasMany<LandedCostAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(LandedCostAllocation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
