<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Immutable rate set (USD→IQD, EUR→USD) used by every financial row. Append-only.
 *
 * @property int $id
 * @property string $usd_iqd
 * @property string $eur_usd
 */
class FxSnapshot extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'usd_iqd' => 'decimal:8',
            'eur_usd' => 'decimal:8',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('FxSnapshot rows are append-only.'));
        static::deleting(fn () => throw new LogicException('FxSnapshot rows are append-only.'));
    }

    /** @return BelongsTo<ExchangeRate, $this> */
    public function usdIqdRate(): BelongsTo
    {
        return $this->belongsTo(ExchangeRate::class, 'usd_iqd_rate_id');
    }

    /** @return BelongsTo<ExchangeRate, $this> */
    public function eurUsdRate(): BelongsTo
    {
        return $this->belongsTo(ExchangeRate::class, 'eur_usd_rate_id');
    }
}
