<?php

namespace App\Services\Branches\Checks;

use App\Enums\InstrumentStatus;
use App\Enums\KitCheckoutStatus;
use App\Enums\KitStatus;
use App\Enums\SurgeryStatus;
use App\Models\Branch;
use App\Models\Instrument;
use App\Models\Kit;
use App\Models\KitCheckout;
use App\Models\Patient;
use App\Models\Surgery;
use App\Services\Branches\Contracts\BranchDependencyCheck;

/**
 * Kits and instruments must leave (or be retired) and kits come back from hospitals, and
 * draft surgeries must be finished, before a branch closes (Module 1 + 18).
 */
class ClinicalCheck implements BranchDependencyCheck
{
    public function closureBlocker(Branch $branch): ?string
    {
        $kits = Kit::query()->where('branch_id', $branch->id)->where('status', '<>', KitStatus::Retired)->count();
        $instruments = Instrument::query()->where('branch_id', $branch->id)->whereNotIn('status', [InstrumentStatus::Retired])->count();
        $out = KitCheckout::query()->where('branch_id', $branch->id)->where('status', KitCheckoutStatus::Out)->count();
        $drafts = Surgery::query()->where('branch_id', $branch->id)->where('status', SurgeryStatus::Draft)->count();

        if ($kits + $instruments + $out + $drafts === 0) {
            return null;
        }

        return __('Instrument sets and instruments still here: :kits instrument sets, :instruments instruments; :out instrument sets out at hospitals; :drafts draft surgeries. Move, retire or finish them first.', [
            'kits' => $kits,
            'instruments' => $instruments,
            'out' => $out,
            'drafts' => $drafts,
        ]);
    }

    public function hasRecords(Branch $branch): bool
    {
        return Kit::query()->where('home_branch_id', $branch->id)->orWhere('branch_id', $branch->id)->exists()
            || Instrument::query()->where('branch_id', $branch->id)->exists()
            || Surgery::query()->where('branch_id', $branch->id)->exists()
            || Patient::query()->where('branch_id', $branch->id)->exists();
    }
}
