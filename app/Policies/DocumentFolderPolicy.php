<?php

namespace App\Policies;

use App\Models\DocumentFolder;
use App\Models\User;

/**
 * Document folders: added, renamed and hidden with documents.folders. Never deleted.
 */
class DocumentFolderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('documents.folders');
    }

    public function view(User $user, DocumentFolder $folder): bool
    {
        return $user->can('documents.view') || $user->can('documents.folders');
    }

    public function create(User $user): bool
    {
        return $user->can('documents.folders');
    }

    public function update(User $user, DocumentFolder $folder): bool
    {
        return $user->can('documents.folders');
    }

    public function delete(User $user, DocumentFolder $folder): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
