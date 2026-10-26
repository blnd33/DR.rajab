<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One raw scan in a stock count (possibly synced later from a phone that was offline).
 * client_uuid makes the sync idempotent.
 *
 * @property int $id
 * @property int $stock_count_id
 * @property string $client_uuid
 * @property string $raw_code
 * @property ?array<string, mixed> $parsed
 * @property int $quantity
 * @property int $scanned_by
 * @property Carbon $scanned_at
 * @property ?string $device_label
 */
class StockCountScan extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'parsed' => 'array',
            'quantity' => 'integer',
            'scanned_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<StockCount, $this> */
    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class);
    }

    /** @return BelongsTo<User, $this> */
    public function scanner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by');
    }

    /** @return MorphTo<Model, $this> */
    public function resolved(): MorphTo
    {
        return $this->morphTo();
    }
}
