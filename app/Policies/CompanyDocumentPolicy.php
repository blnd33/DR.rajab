<?php

namespace App\Policies;

use App\Models\CompanyDocument;
use App\Models\User;

/**
 * Company documents: seen with documents.view, uploaded / edited / archived with
 * documents.upload. Never deleted (archived with a reason).
 */
class CompanyDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('documents.view');
    }

    public function view(User $user, CompanyDocument $document): bool
    {
        return $user->can('documents.view');
    }

    public function create(User $user): bool
    {
        return $user->can('documents.upload');
    }

    public function update(User $user, CompanyDocument $document): bool
    {
        return $user->can('documents.upload');
    }

    public function delete(User $user, CompanyDocument $document): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
