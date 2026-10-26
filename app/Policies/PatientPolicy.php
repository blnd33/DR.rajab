<?php

namespace App\Policies;

use App\Models\Patient;
use App\Models\User;
use App\Services\Clinical\PatientService;
use App\Services\Permissions\BranchScopeResolver;

/**
 * Patients (sensitive): own patients for doctors, all with patients.view_all. Never deleted.
 */
class PatientPolicy
{
    public function viewAny(User $user): bool
    {
        $scope = app(BranchScopeResolver::class);

        return $scope->hasAny($user, 'patients.view_own') || $scope->hasAny($user, 'patients.view_all');
    }

    public function view(User $user, Patient $patient): bool
    {
        return app(PatientService::class)->canSee($user, $patient);
    }

    public function create(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, 'patients.manage');
    }

    public function update(User $user, Patient $patient): bool
    {
        return $user->canInBranch('patients.manage', $patient->branch_id) && $this->view($user, $patient);
    }

    public function delete(User $user, Patient $patient): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
