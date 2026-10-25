<?php

namespace App\Policies;

use App\Enums\ImportType;
use App\Models\DataImport;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;

/**
 * Excel imports: anyone allowed to run at least one import type sees the import list;
 * an import is visible to its uploader (and the Super Admin through Gate::before).
 */
class DataImportPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canRunAny($user);
    }

    public function view(User $user, DataImport $import): bool
    {
        return $import->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $this->canRunAny($user);
    }

    public function update(User $user, DataImport $import): bool
    {
        return false;
    }

    public function delete(User $user, DataImport $import): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function canRunAny(User $user): bool
    {
        $scope = app(BranchScopeResolver::class);

        foreach (ImportType::cases() as $type) {
            if ($scope->hasAny($user, $type->permission())) {
                return true;
            }
        }

        return false;
    }
}
