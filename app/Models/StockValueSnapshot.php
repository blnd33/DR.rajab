<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Stock value of one branch in one cost currency on one day (nightly, P5-4).
 *
 * @property int $id
 * @property Carbon $snapshot_date
 * @property int $branch_id
 * @property string $currency
 * @property string $value
 * @property ?string $value_usd
 * @property int $units
 * @property ?int $fx_snapshot_id
 */
class StockValueSnapshot extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'value' => 'decimal:4',
            'value_usd' => 'decimal:4',
            'units' => 'integer',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
