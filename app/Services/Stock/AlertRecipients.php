<?php

namespace App\Services\Stock;

use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;
use Illuminate\Support\Collection;

/**
 * Active users who hold a permission in a branch — the people a branch alert goes to.
 */
class AlertRecipients
{
    /** @var Collection<int, User>|null */
    private ?Collection $users = null;

    public function __construct(private readonly BranchScopeResolver $scope) {}

    /**
     * @return Collection<int, User>
     */
    public function for(string $permission, int $branchId): Collection
    {
        $this->users ??= User::query()->active()->with(['roles', 'permissions'])->get();

        return $this->users->filter(fn (User $user) => $this->scope->allows($user, $permission, $branchId))->values();
    }
}
