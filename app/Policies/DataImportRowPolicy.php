<?php

namespace App\Policies;

use App\Models\DataImportRow;
use App\Models\User;

/**
 * Rows of an import; only shown inside an import the user may open.
 */
class DataImportRowPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, DataImportRow $dataImportRow): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, DataImportRow $dataImportRow): bool
    {
        return false;
    }

    public function delete(User $user, DataImportRow $dataImportRow): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
