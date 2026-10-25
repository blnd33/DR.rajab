<?php

namespace App\Services\Branches\Contracts;

use App\Models\Branch;

/**
 * One check per module (users, stock, transfers, cash, kits…). Each phase adds
 * its own check class to config('orthotrack.branch_checks').
 */
interface BranchDependencyCheck
{
    /**
     * Why the branch cannot be closed yet, or null when this module is clear.
     */
    public function closureBlocker(Branch $branch): ?string;

    /**
     * True when the module has any record for this branch (blocks a true delete).
     */
    public function hasRecords(Branch $branch): bool;
}
