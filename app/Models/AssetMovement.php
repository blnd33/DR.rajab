<?php

namespace App\Models;

use App\Enums\AssetMovementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Ledger for kits and instruments (append-only — the database rejects UPDATE/DELETE).
 * Written only by AssetMovementService.
 *
 * @property int $id
 * @property string $movement_group
 * @property string $asset_type
 * @property int $asset_id
 * @property AssetMovementType $type
 * @property ?int $from_branch_id
 * @property ?int $to_branch_id
 * @property ?string $from_status
 * @property ?string $to_status
 * @property ?int $from_kit_id
 * @property ?int $to_kit_id
 * @property ?int $customer_id
 * @property ?int $holder_user_id
 * @property ?string $note
 * @property int $user_id
 * @property Carbon $occurred_at
 */
class AssetMovement extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => AssetMovementType::class,
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Asset movements are append-only.'));
        static::deleting(fn () => throw new LogicException('Asset movements are append-only.'));
    }

    /** @return MorphTo<Model, $this> */
    public function asset(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model, $this> */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
