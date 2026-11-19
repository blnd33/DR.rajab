<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;
use App\Support\AdminBranch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin panel: narrow the signed-in user's branch scope to the branch chosen in the top bar.
 * A branch the user may no longer use is forgotten.
 */
class FocusAdminBranch
{
    public function __construct(private readonly BranchScopeResolver $scope) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $branchId = AdminBranch::id();

        if ($user instanceof User && $branchId !== null) {
            if (AdminBranch::allows($user, $branchId)) {
                $this->scope->focus($user, $branchId);
            } else {
                AdminBranch::set(null);
            }
        }

        return $next($request);
    }
}
