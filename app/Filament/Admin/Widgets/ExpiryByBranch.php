<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Branch;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;
use App\Services\Stock\StockQuery;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

/**
 * Expiry counts per level per branch, in the Super Admin's own colors (Module 6 / 26).
 */
class ExpiryByBranch extends Widget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.expiry-by-branch';

    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('dashboard.view') && app(BranchScopeResolver::class)->hasAny($user, 'stock.view');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = Auth::user();
        $scope = app(BranchScopeResolver::class);
        $branchIds = $scope->branchIdsFor($user, 'stock.view');

        return [
            'counts' => app(StockQuery::class)->expiryCounts($branchIds),
            'branches' => $scope->apply(Branch::query()->active()->orderBy('code'), $user, 'stock.view', 'id')->get(),
        ];
    }
}
