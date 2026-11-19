<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\CheckoutItemOutcome;
use App\Enums\KitCheckoutStatus;
use App\Enums\SurgeryStatus;
use App\Filament\Admin\Resources\KitCheckouts\KitCheckoutResource;
use App\Filament\Admin\Resources\Surgeries\SurgeryResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\KitCheckout;
use App\Models\KitCheckoutItem;
use App\Models\User;
use App\Services\Clinical\SurgeryService;
use App\Services\Permissions\BranchScopeResolver;
use App\Support\Locale\Display;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * Surgeries today / this month, kits out and overdue, unaccounted implants (Module 26).
 */
class ClinicalOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = Auth::user();
        $scope = app(BranchScopeResolver::class);

        return $user instanceof User && $user->can('dashboard.view')
            && ($scope->hasAny($user, 'surgeries.view_any') || $scope->hasAny($user, 'kits.view_any'));
    }

    protected function getHeading(): ?string
    {
        return __('Surgeries and instrument sets');
    }

    protected function getStats(): array
    {
        /** @var User $user */
        $user = Auth::user();
        $scope = app(BranchScopeResolver::class);
        $stats = [];

        if ($scope->hasAny($user, 'surgeries.view_any')) {
            $surgeries = fn () => app(SurgeryService::class)->visibleTo($user)->where('status', SurgeryStatus::Completed);
            $stats[] = Stat::make(__('Surgeries today'), Display::number($surgeries()->whereDate('surgery_date', now()->toDateString())->count()))
                ->icon(Heroicon::OutlinedHeart)
                ->url(SurgeryResource::getUrl('index'));
            $stats[] = Stat::make(__('Surgeries this month'), Display::number($surgeries()->where('surgery_date', '>=', now()->startOfMonth()->toDateString())->count()))
                ->icon(Heroicon::OutlinedCalendarDays)
                ->url(SurgeryResource::getUrl('index', ['filters' => ['this_month' => ['isActive' => true]]]));
        }

        if ($scope->hasAny($user, 'kits.view_any')) {
            $out = ServiceAction::scoped(KitCheckout::query(), 'kits.view_any', ['branch_id'])->where('status', KitCheckoutStatus::Out);
            $overdue = (clone $out)->where('due_back_at', '<', now())->count();
            $unaccounted = KitCheckoutItem::query()->where('outcome', CheckoutItemOutcome::Unaccounted)->where('qty_unaccounted', '>', 0)
                ->whereIn('kit_checkout_id', ServiceAction::scoped(KitCheckout::query(), 'kits.view_any', ['branch_id'])->select('id'))
                ->sum('qty_unaccounted');

            $stats[] = Stat::make(__('Instrument sets out at hospitals'), Display::number($out->count()))
                ->description($overdue > 0 ? __(':count overdue', ['count' => Display::number($overdue)]) : null)
                ->descriptionColor('danger')
                ->icon(Heroicon::OutlinedBriefcase)
                ->color($overdue > 0 ? 'danger' : 'gray')
                ->url(KitCheckoutResource::getUrl('index'));
            $stats[] = Stat::make(__('Unaccounted implants'), Display::number((int) $unaccounted))
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color($unaccounted > 0 ? 'danger' : 'success')
                ->url(KitCheckoutResource::getUrl('index'));
        }

        return $stats;
    }
}
