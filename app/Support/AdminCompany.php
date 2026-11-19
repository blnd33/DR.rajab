<?php

namespace App\Support;

use App\Enums\Company;

/**
 * The company chosen in the admin top bar (stored in the session; the main company by default).
 * Another company has no stations yet: everything branch-based shows nothing for it
 * (FocusAdminBranch → BranchScopeResolver::focusNoBranches).
 */
final class AdminCompany
{
    public const SESSION_KEY = 'admin.company';

    public static function current(): Company
    {
        if (! app()->bound('session')) {
            return Company::main();
        }

        return Company::tryFrom((string) session(self::SESSION_KEY)) ?? Company::main();
    }

    public static function set(Company $company): void
    {
        $company->isMain() ? session()->forget(self::SESSION_KEY) : session([self::SESSION_KEY => $company->value]);
    }
}
