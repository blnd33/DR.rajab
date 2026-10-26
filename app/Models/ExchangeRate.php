<?php

namespace App\Models;

use App\Enums\RateSource;
use App\Enums\RateStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An exchange rate entry (append-only history; status moves from suggested to confirmed / rejected).
 *
 * @property int $id
 * @property string $rate
 * @property RateSource $source
 * @property RateStatus $status
 * @property Carbon $effective_at
 * @property ?Carbon $confirmed_at
 * @property string $base_currency
 * @property string $quote_currency
 */
class ExchangeRate extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:8',
            'source' => RateSource::class,
            'status' => RateStatus::class,
            'effective_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function setter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }

    /** @return BelongsTo<User, $this> */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
