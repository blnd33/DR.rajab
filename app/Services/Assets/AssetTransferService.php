<?php

namespace App\Services\Assets;

use App\Enums\AssetMovementType;
use App\Enums\InstrumentStatus;
use App\Enums\KitStatus;
use App\Enums\LocationType;
use App\Enums\StockStatus;
use App\Enums\TransferAssetStatus;
use App\Enums\TransferStatus;
use App\Exceptions\Domain\StockRuleViolation;
use App\Models\Instrument;
use App\Models\Kit;
use App\Models\StockBatch;
use App\Models\StockUnit;
use App\Models\Transfer;
use App\Models\TransferAsset;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;
use App\Services\Stock\StockMovementService;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Kits (with everything in them) and loose instruments on a transfer (P3-8). Called by
 * TransferService inside its send / receive / cancel transactions.
 */
class AssetTransferService
{
    public function __construct(
        private readonly AssetMovementService $assets,
        private readonly StockMovementService $stock,
        private readonly BranchScopeResolver $scope,
    ) {}

    public function addKit(Transfer $transfer, Kit $kit, User $by): TransferAsset
    {
        $this->assertDraft($transfer, $by);

        if ($kit->branch_id !== $transfer->from_branch_id || $kit->status !== KitStatus::Available) {
            throw new StockRuleViolation(__('Instrument set :kit is :status in :branch and cannot be sent.', ['kit' => $kit->code, 'status' => $kit->status->getLabel(), 'branch' => $kit->branch->code]));
        }

        $this->assertNotOnAnotherTransfer('kit_id', $kit->id, $kit->code);

        return $transfer->assets()->firstOrCreate(['kit_id' => $kit->id], ['status' => TransferAssetStatus::Pending]);
    }

    public function addInstrument(Transfer $transfer, Instrument $instrument, User $by): TransferAsset
    {
        $this->assertDraft($transfer, $by);

        if ($instrument->kit_id !== null) {
            throw new StockRuleViolation(__(':code is in instrument set :kit — send the instrument set instead.', ['code' => $instrument->code, 'kit' => $instrument->kit->code]));
        }

        if ($instrument->branch_id !== $transfer->from_branch_id || $instrument->status !== InstrumentStatus::Available) {
            throw new StockRuleViolation(__('Instrument :code is :status in :branch and cannot go into this instrument set.', ['code' => $instrument->code, 'status' => $instrument->status->getLabel(), 'branch' => $instrument->branch->code]));
        }

        $this->assertNotOnAnotherTransfer('instrument_id', $instrument->id, $instrument->code);

        return $transfer->assets()->firstOrCreate(['instrument_id' => $instrument->id], ['status' => TransferAssetStatus::Pending]);
    }

    public function remove(TransferAsset $asset, User $by): void
    {
        $this->assertDraft($asset->transfer, $by);
        $asset->delete();
    }

    public function sendAll(Transfer $transfer, User $by, string $group): void
    {
        foreach ($transfer->assets()->with(['kit', 'instrument'])->get() as $asset) {
            if ($asset->kit !== null) {
                $kit = $asset->kit;

                if ($kit->status !== KitStatus::Available) {
                    throw new StockRuleViolation(__('Instrument set :kit is :status.', ['kit' => $kit->code, 'status' => $kit->status->getLabel()]));
                }

                $this->assets->move($kit, AssetMovementType::TransferSend, $by, ['status' => KitStatus::InTransit], $transfer, $group);

                foreach ($kit->instruments()->where('status', InstrumentStatus::InKit)->get() as $instrument) {
                    $this->assets->move($instrument, AssetMovementType::TransferSend, $by, ['status' => InstrumentStatus::InTransit], $transfer, $group);
                }

                continue;
            }

            $this->assets->move($asset->instrument, AssetMovementType::TransferSend, $by, ['status' => InstrumentStatus::InTransit], $transfer, $group);
        }
    }

    /**
     * The kit arrives with everything in it: kit, instruments and implants change branch.
     */
    public function receiveAll(Transfer $transfer, User $by, string $group): void
    {
        $to = $transfer->toBranch;

        foreach ($transfer->assets()->with(['kit', 'instrument'])->get() as $asset) {
            if ($asset->kit !== null) {
                $kit = $asset->kit;

                foreach ($kit->instruments()->where('status', InstrumentStatus::InTransit)->get() as $instrument) {
                    $this->assets->move($instrument, AssetMovementType::TransferReceive, $by, ['status' => InstrumentStatus::InKit, 'branch_id' => $to->id], $transfer, $group);
                }

                foreach (StockUnit::query()->where('kit_id', $kit->id)->where('status', StockStatus::InKit)->get() as $unit) {
                    $this->stock->moveKitUnitToBranch($unit, $to, $by, $transfer, $group);
                }

                foreach (StockBatch::query()->where('kit_id', $kit->id)->where('location_type', LocationType::Kit)->where('quantity', '>', 0)->get() as $batch) {
                    $this->stock->moveKitBatchToBranch($batch, $to, $by, $transfer, $group);
                }

                $this->assets->move($kit, AssetMovementType::TransferReceive, $by, ['status' => KitStatus::Available, 'branch_id' => $to->id], $transfer, $group);
            } else {
                $this->assets->move($asset->instrument, AssetMovementType::TransferReceive, $by, ['status' => InstrumentStatus::Available, 'branch_id' => $to->id], $transfer, $group);
            }

            $asset->update(['status' => TransferAssetStatus::Received]);
        }
    }

    public function cancelAll(Transfer $transfer, User $by, string $group): void
    {
        foreach ($transfer->assets()->with(['kit', 'instrument'])->get() as $asset) {
            if ($asset->kit !== null) {
                foreach ($asset->kit->instruments()->where('status', InstrumentStatus::InTransit)->get() as $instrument) {
                    $this->assets->move($instrument, AssetMovementType::TransferCancel, $by, ['status' => InstrumentStatus::InKit], $transfer, $group);
                }

                $this->assets->move($asset->kit, AssetMovementType::TransferCancel, $by, ['status' => KitStatus::Available], $transfer, $group);

                continue;
            }

            $this->assets->move($asset->instrument, AssetMovementType::TransferCancel, $by, ['status' => InstrumentStatus::Available], $transfer, $group);
        }
    }

    private function assertDraft(Transfer $transfer, User $by): void
    {
        if (! $this->scope->allows($by, 'transfers.create', $transfer->from_branch_id)) {
            throw new AuthorizationException(__('You are not allowed to do this in this station.'));
        }

        if ($transfer->status !== TransferStatus::Draft) {
            throw new StockRuleViolation(__('Transfer :number was already sent.', ['number' => $transfer->number]));
        }
    }

    private function assertNotOnAnotherTransfer(string $column, int $id, string $code): void
    {
        $busy = TransferAsset::query()->where($column, $id)
            ->whereHas('transfer', fn ($q) => $q->whereIn('status', [TransferStatus::Draft, TransferStatus::InTransit]))
            ->exists();

        if ($busy) {
            throw new StockRuleViolation(__(':code is already on another open transfer.', ['code' => $code]));
        }
    }
}
