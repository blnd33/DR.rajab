<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;
use App\Support\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Ops app branch switcher: only open branches in the user's stock.view scope.
 */
class BranchSwitchController extends Controller
{
    public function __invoke(Request $request, BranchScopeResolver $scope): RedirectResponse
    {
        $data = $request->validate(['branch_id' => ['required', 'integer']]);

        /** @var User $user */
        $user = $request->user();

        $allowed = $scope->selectableBranches($user, 'stock.view')->whereKey($data['branch_id'])->exists();

        abort_unless($allowed, 403);

        CurrentBranch::set((int) $data['branch_id']);

        return redirect()->back();
    }
}
