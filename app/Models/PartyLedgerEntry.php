<?php

namespace App\Models;

use App\Enums\PartyEntryType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Supplier / customer statement line per currency (append-only; + increases what is owed).
 *
 * @property int $id
 * @property PartyEntryType $entry_type
 * @property string $amount
 * @property Carbon $occurred_at
 * @property string $currency
 * @property ?string $description
 */
class PartyLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'entry_type' => PartyEntryType::class,
            'amount' => 'decimal:4',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('PartyLedgerEntry rows are append-only.'));
        static::deleting(fn () => throw new LogicException('PartyLedgerEntry rows are append-only.'));
    }

    /** @return MorphTo<Model, $this> */
    public function party(): MorphTo
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
}
