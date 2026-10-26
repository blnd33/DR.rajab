<?php

namespace App\Services\Assets;

use App\Enums\AssetMovementType;
use App\Models\AssetMovement;
use App\Models\Instrument;
use App\Models\Kit;
use App\Models\User;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of kit / instrument status, branch, kit membership, hospital and holder:
 * lock the row, apply the change, append one asset_movements row (append-only ledger).
 * Callers (KitService, InstrumentService, TransferService) check permissions and rules.
 */
class AssetMovementService
{
    /**
     * @param  array{status?: BackedEnum, branch_id?: int, kit_id?: ?int, customer_id?: ?int, holder_user_id?: ?int}  $changes
     */
    public function move(Kit|Instrument $asset, AssetMovementType $type, User $by, array $changes = [], ?Model $reference = null, ?string $group = null, ?string $note = null): AssetMovement
    {
        return DB::transaction(function () use ($asset, $type, $by, $changes, $reference, $group, $note) {
            /** @var Kit|Instrument $locked */
            $locked = $asset::query()->lockForUpdate()->findOrFail($asset->getKey());

            $fromStatus = $locked->status->value;
            $fromBranch = $locked->branch_id;
            $fromKit = $locked instanceof Instrument ? $locked->kit_id : null;

            $locked->forceFill($changes)->save();
            $asset->setRawAttributes($locked->getAttributes(), true);

            $movement = new AssetMovement([
                'movement_group' => $group ?? (string) str()->uuid(),
                'type' => $type,
                'from_branch_id' => $fromBranch,
                'to_branch_id' => $locked->branch_id,
                'from_status' => $fromStatus,
                'to_status' => $locked->status->value,
                'from_kit_id' => $fromKit,
                'to_kit_id' => $locked instanceof Instrument ? $locked->kit_id : null,
                'customer_id' => $locked->customer_id,
                'holder_user_id' => $locked instanceof Instrument ? $locked->holder_user_id : null,
                'note' => $note,
                'user_id' => $by->id,
                'occurred_at' => now(),
            ]);

            $movement->asset()->associate($locked);

            if ($reference !== null) {
                $movement->reference()->associate($reference);
            }

            $movement->save();

            return $movement;
        });
    }
}
