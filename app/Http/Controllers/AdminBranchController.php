<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\AdminBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The admin top-bar branch switch ("all" = all the user's branches).
 */
class AdminBranchController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $choice = (string) $request->input('branch_id', 'all');

        if ($choice === 'all' || $choice === '') {
            AdminBranch::set(null);
        } else {
            abort_unless(ctype_digit($choice) && AdminBranch::allows($user, (int) $choice), 403);
            AdminBranch::set((int) $choice);
        }

        return redirect()->back();
    }
}
