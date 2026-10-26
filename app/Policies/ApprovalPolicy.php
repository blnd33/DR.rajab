<?php

namespace App\Policies;

use App\Enums\ApprovalType;
use App\Models\Approval;
use App\Models\User;
use App\Services\Money\ApprovalService;
use App\Services\Permissions\BranchScopeResolver;

/**
 * The approvals queue: approvers see what they may decide; requesters see their own.
 */
class ApprovalPolicy
{
    public function viewAny(User $user): bool
    {
        $scope = app(BranchScopeResolver::class);

        return collect(ApprovalType::cases())->contains(fn (ApprovalType $t) => $scope->hasAny($user, ApprovalService::permission($t)))
            || $scope->hasAny($user, 'expenses.create')
            || $user->can('supplier_payments.create');
    }

    public function view(User $user, Approval $approval): bool
    {
        return $approval->requested_by === $user->id
            || $user->canInBranch(ApprovalService::permission($approval->type), $approval->branch_id);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Approval $approval): bool
    {
        return false;
    }

    public function delete(User $user, Approval $approval): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
