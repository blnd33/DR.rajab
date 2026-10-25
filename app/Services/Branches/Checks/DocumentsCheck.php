<?php

namespace App\Services\Branches\Checks;

use App\Models\Branch;
use App\Models\DocumentSequence;
use App\Services\Branches\Contracts\BranchDependencyCheck;

/**
 * Any document number ever issued for the branch means it has history.
 */
class DocumentsCheck implements BranchDependencyCheck
{
    public function closureBlocker(Branch $branch): ?string
    {
        return null;
    }

    public function hasRecords(Branch $branch): bool
    {
        return DocumentSequence::query()->where('branch_id', $branch->id)->where('next_number', '>', 1)->exists();
    }
}
