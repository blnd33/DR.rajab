<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Pages\LowStockReport;
use App\Filament\Admin\Resources\StockUnits\StockUnitResource;
use App\Models\User;
use App\Services\Money\FxService;
use App\Services\Permissions\BranchScopeResolver;
use App\Services\Stock\LowStockDetector;
use App\Services\Stock\StockQuery;
use App\Support\Locale\Display;
use Brick\Math\BigDecimal;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * Stock cards: value (true cost, in USD at today's rates), low stock, expired, next level.
 */
class StockOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('dashboard.view') && app(BranchScopeResolver::class)->hasAny($user, 'stock.view');
    }

    protected function getStats(): array
    {
        /** @var User $user */
        $user = Auth::user();
        $scope = app(BranchScopeResolver::class);
        $query = app(StockQuery::class);
        $branchIds = $scope->branchIdsFor($user, 'stock.view');

        $stats = [];

        if ($scope->hasAny($user, 'stock.view_cost')) {
            $values = $query->valueByCurrency($scope->branchIdsFor($user, 'stock.view_cost'));
            $perCurrency = implode(' · ', array_map(fn ($currency, $value) => $currency.' '.Display::number($value, 0), array_keys($values), $values));
            $snapshot = app(FxService::class)->current();

            if ($snapshot !== null && $values !== []) {
                $converter = app(FxService::class)->converter($snapshot);
                $usd = array_reduce(array_keys($values), fn (BigDecimal $sum, $currency) => $sum->plus($converter->toUsd($values[$currency], (string) $currency)), BigDecimal::zero());
                $stats[] = Stat::make(__('Stock value (true cost)'), Display::money((string) $usd, 'USD'))
                    ->description(count($values) > 1 ? $perCurrency : __('At today\'s rates'))
                    ->icon(Heroicon::OutlinedBanknotes);
            } else {
                $stats[] = Stat::make(__('Stock value (true cost)'), $values === [] ? Display::number(0) : $perCurrency)
                    ->description(__('Per currency — set the exchange rates for one total.'))
                    ->icon(Heroicon::OutlinedBanknotes);
            }
        }

        $lowStock = app(LowStockDetector::class)->query($user)->count();
        $stats[] = Stat::make(__('Below minimum'), Display::number($lowStock))
            ->icon(Heroicon::OutlinedArrowTrendingDown)
            ->color($lowStock > 0 ? 'danger' : 'success')
            ->url(LowStockReport::getUrl());

        $counts = collect($query->expiryCounts($branchIds));
        $expired = $counts->first(fn ($c) => $c['level']->is_expired_level);
        $next = $counts->first(fn ($c) => ! $c['level']->is_expired_level);

        if ($expired !== null) {
            $stats[] = Stat::make(__('Expired items'), Display::number($expired['total']))
                ->description(__('Blocked from surgery, sale and transfer'))
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color($expired['total'] > 0 ? 'danger' : 'gray')
                ->url(StockUnitResource::getUrl('index', ['filters' => ['expiry_level' => ['value' => $expired['level']->id]]]));
        }

        if ($next !== null) {
            $stats[] = Stat::make($next['level']->name, Display::number($next['total']))
                ->description(__(':range days left', ['range' => $next['level']->rangeLabel()]))
                ->icon(Heroicon::OutlinedClock)
                ->color($next['total'] > 0 ? 'warning' : 'gray')
                ->url(StockUnitResource::getUrl('index', ['filters' => ['expiry_level' => ['value' => $next['level']->id]]]));
        }

        return $stats;
    }
}
