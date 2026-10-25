<?php

namespace App\Policies;

use App\Models\UnrecognizedScan;
use App\Models\User;

/**
 * Codes nobody could read — for the admin who adds new barcode formats. Read-only.
 */
class UnrecognizedScanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('settings.manage');
    }

    public function view(User $user, UnrecognizedScan $scan): bool
    {
        return $user->can('settings.manage');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, UnrecognizedScan $scan): bool
    {
        return false;
    }

    public function delete(User $user, UnrecognizedScan $scan): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
