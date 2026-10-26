<?php

namespace App\Models;

use App\Enums\TransferAssetStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A kit (with everything in it) or a loose instrument on a transfer.
 *
 * @property int $id
 * @property int $transfer_id
 * @property ?int $kit_id
 * @property ?int $instrument_id
 * @property TransferAssetStatus $status
 */
class TransferAsset extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => TransferAssetStatus::class];
    }

    /** @return BelongsTo<Transfer, $this> */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    /** @return BelongsTo<Kit, $this> */
    public function kit(): BelongsTo
    {
        return $this->belongsTo(Kit::class);
    }

    /** @return BelongsTo<Instrument, $this> */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }

    public function code(): string
    {
        return $this->kit->code ?? ($this->instrument->code ?? '—');
    }
}
