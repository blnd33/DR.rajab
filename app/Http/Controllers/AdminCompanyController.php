<?php

namespace App\Http\Controllers;

use App\Enums\Company;
use App\Filament\Admin\Pages\Dashboard;
use App\Support\AdminBranch;
use App\Support\AdminCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The admin top-bar company switch: opens the Main page of the chosen company.
 */
class AdminCompanyController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $company = Company::tryFrom((string) $request->input('company'));
        abort_if($company === null, 404);

        AdminCompany::set($company);
        // The station picked for one company means nothing in another.
        AdminBranch::set(null);

        return redirect()->to(Dashboard::getUrl(panel: 'admin'));
    }
}
