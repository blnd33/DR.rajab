<?php

namespace App\Services\Assets;

use App\Enums\AssetMovementType;
use App\Enums\InstrumentStatus;
use App\Exceptions\Domain\StockRuleViolation;
use App\Models\Branch;
use App\Models\Instrument;
use App\Models\User;
use App\Services\Numbering\DocumentNumberGenerator;
use App\Services\Permissions\BranchScopeResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Reusable instruments (Module 18): register, maintenance, missing / found, holder, retire.
 * Kit membership is changed by KitService; travel by TransferService.
 */
class InstrumentService
{
    public function __construct(
        private readonly AssetMovementService $assets,
        private readonly BranchScopeResolver $scope,
        private readonly DocumentNumberGenerator $numbers,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Branch $branch, array $data, User $by): Instrument
    {
        $this->authorize($by, $branch->id);

        return DB::transaction(function () use ($branch, $data, $by) {
            $instrument = new Instrument($data);
            $instrument->code = filled($data['code'] ?? null) ? (string) $data['code'] : $this->numbers->next('INS', $branch, 0, 4);
            $instrument->branch_id = $branch->id;
            $instrument->status = InstrumentStatus::Available;
            $instrument->save();

            $this->assets->move($instrument, AssetMovementType::Create, $by);

            return $instrument;
        });
    }

    public function sendToMaintenance(Instrument $instrument, User $by, ?string $note = null): void
    {
        $this->authorize($by, $instrument->branch_id);
        $this->assertLoose($instrument, [InstrumentStatus::Available, InstrumentStatus::Damaged]);
        $this->assets->move($instrument, AssetMovementType::MaintenanceIn, $by, ['status' => InstrumentStatus::InMaintenance], note: $note);
    }

    public function backFromMaintenance(Instrument $instrument, User $by, ?string $note = null): void
    {
        $this->authorize($by, $instrument->branch_id);
        $this->assertLoose($instrument, [InstrumentStatus::InMaintenance]);
        $this->assets->move($instrument, AssetMovementType::MaintenanceOut, $by, ['status' => InstrumentStatus::Available], note: $note);
    }

    public function markMissing(Instrument $instrument, User $by, string $note): void
    {
        $this->authorize($by, $instrument->branch_id);
        $this->requireNote($note);
        $this->assertLoose($instrument, [InstrumentStatus::Available, InstrumentStatus::InMaintenance, InstrumentStatus::Damaged]);
        $this->assets->move($instrument, AssetMovementType::MarkMissing, $by, ['status' => InstrumentStatus::Missing], note: $note);
    }

    public function markFound(Instrument $instrument, User $by, ?string $note = null): void
    {
        $this->authorize($by, $instrument->branch_id);
        $this->assertLoose($instrument, [InstrumentStatus::Missing]);
        $this->assets->move($instrument, AssetMovementType::Found, $by, ['status' => InstrumentStatus::Available], note: $note);
    }

    public function assignHolder(Instrument $instrument, ?User $holder, User $by): void
    {
        $this->authorize($by, $instrument->branch_id);
        $this->assets->move($instrument, AssetMovementType::AssignHolder, $by, ['holder_user_id' => $holder?->id]);
    }

    public function retire(Instrument $instrument, User $by, string $note): void
    {
        $this->authorize($by, $instrument->branch_id);
        $this->requireNote($note);
        $this->assertLoose($instrument, [InstrumentStatus::Available, InstrumentStatus::InMaintenance, InstrumentStatus::Damaged, InstrumentStatus::Missing]);
        $this->assets->move($instrument, AssetMovementType::Retire, $by, ['status' => InstrumentStatus::Retired, 'holder_user_id' => null], note: $note);
    }

    /**
     * @param  list<InstrumentStatus>  $allowed
     */
    private function assertLoose(Instrument $instrument, array $allowed): void
    {
        if ($instrument->kit_id !== null) {
            throw new StockRuleViolation(__('Take :code out of its instrument set first.', ['code' => $instrument->code]));
        }

        if (! in_array($instrument->status, $allowed, true)) {
            throw new StockRuleViolation(__('Instrument :code is :status.', ['code' => $instrument->code, 'status' => $instrument->status->getLabel()]));
        }
    }

    private function requireNote(?string $note): void
    {
        if (blank($note)) {
            throw new StockRuleViolation(__('A reason is required.'), 'note');
        }
    }

    private function authorize(User $by, int $branchId): void
    {
        if (! $this->scope->allows($by, 'instruments.manage', $branchId)) {
            throw new AuthorizationException(__('You are not allowed to do this in this station.'));
        }
    }
}
