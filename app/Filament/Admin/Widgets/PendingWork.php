<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\IssueStatus;
use App\Enums\StockCountStatus;
use App\Enums\StockRequestStatus;
use App\Enums\TransferStatus;
use App\Enums\WriteOffStatus;
use App\Filament\Admin\Resources\Issues\IssueResource;
use App\Filament\Admin\Resources\StockCounts\StockCountResource;
use App\Filament\Admin\Resources\StockRequests\StockRequestResource;
use App\Filament\Admin\Resources\Transfers\TransferResource;
use App\Filament\Admin\Resources\WriteOffs\WriteOffResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\Issue;
use App\Models\StockCount;
use App\Models\StockRequest;
use App\Models\Transfer;
use App\Models\User;
use App\Models\WriteOff;
use App\Services\Permissions\BranchScopeResolver;
use App\Support\Locale\Display;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * Documents waiting for someone: approvals, transfers on the road, counts to review, open issues.
 */
class PendingWork extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('dashboard.view') && app(BranchScopeResolver::class)->hasAny($user, 'transfers.view_any');
    }

    protected function getHeading(): ?string
    {
        return __('Waiting for action');
    }

    protected function getStats(): array
    {
        /** @var User $user */
        $user = Auth::user();
        $scope = app(BranchScopeResolver::class);
        $stats = [];

        if ($scope->hasAny($user, 'write_offs.approve')) {
            $n = ServiceAction::scoped(WriteOff::query(), 'write_offs.approve', ['branch_id'])->where('status', WriteOffStatus::Pending)->count();
            $stats[] = Stat::make(__('Write-offs to approve'), Display::number($n))
                ->icon(Heroicon::OutlinedTrash)
                ->color($n > 0 ? 'warning' : 'gray')
                ->url(WriteOffResource::getUrl('index'));
        }

        if ($scope->hasAny($user, 'stock_requests.approve')) {
            $n = ServiceAction::scoped(StockRequest::query(), 'stock_requests.approve', ['requesting_branch_id', 'preferred_source_branch_id'])->where('status', StockRequestStatus::Pending)->count();
            $stats[] = Stat::make(__('Stock requests to approve'), Display::number($n))
                ->icon(Heroicon::OutlinedInboxArrowDown)
                ->color($n > 0 ? 'warning' : 'gray')
                ->url(StockRequestResource::getUrl('index'));
        }

        $n = ServiceAction::scoped(Transfer::query(), 'transfers.view_any', ['from_branch_id', 'to_branch_id'])->where('status', TransferStatus::InTransit)->count();
        $stats[] = Stat::make(__('Transfers in transit'), Display::number($n))
            ->icon(Heroicon::OutlinedTruck)
            ->color($n > 0 ? 'info' : 'gray')
            ->url(TransferResource::getUrl('index'));

        if ($scope->hasAny($user, 'stock_counts.review')) {
            $n = ServiceAction::scoped(StockCount::query(), 'stock_counts.review', ['branch_id'])->whereIn('status', [StockCountStatus::Submitted, StockCountStatus::Reviewed])->count();
            $stats[] = Stat::make(__('Counts to review'), Display::number($n))
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->color($n > 0 ? 'warning' : 'gray')
                ->url(StockCountResource::getUrl('index'));
        }

        if ($scope->hasAny($user, 'issues.view_any')) {
            $n = ServiceAction::scoped(Issue::query(), 'issues.view_any', ['branch_id'])->where('status', IssueStatus::Open)->count();
            $stats[] = Stat::make(__('Open issues'), Display::number($n))
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color($n > 0 ? 'danger' : 'success')
                ->url(IssueResource::getUrl('index'));
        }

        return $stats;
    }
}
