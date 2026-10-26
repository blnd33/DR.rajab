<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\ApprovalStatus;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use App\Filament\Admin\Resources\Approvals\ApprovalResource;
use App\Filament\Admin\Resources\CashBoxes\CashBoxResource;
use App\Filament\Admin\Resources\Purchases\PurchaseResource;
use App\Filament\Admin\Resources\SupplierPayments\SupplierPaymentResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\Approval;
use App\Models\CashBox;
use App\Models\PartyLedgerEntry;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Money\FxService;
use App\Services\Permissions\BranchScopeResolver;
use App\Support\Locale\Display;
use Brick\Math\BigDecimal;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * Money cards (Module 26, Phase 4): money in boxes, owed to suppliers, overdue invoices,
 * approvals waiting. Totals in USD at today's rates, the split per currency underneath.
 */
class MoneyOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = Auth::user();
        $scope = app(BranchScopeResolver::class);

        return $user instanceof User && $user->can('dashboard.view')
            && ($scope->hasAny($user, 'cash_boxes.view_any') || $scope->hasAny($user, 'purchases.view_any'));
    }

    protected function getHeading(): ?string
    {
        return __('Money');
    }

    protected function getStats(): array
    {
        /** @var User $user */
        $user = Auth::user();
        $scope = app(BranchScopeResolver::class);
        $stats = [];

        if ($scope->hasAny($user, 'cash_boxes.view_any')) {
            $balances = ServiceAction::scoped(CashBox::query(), 'cash_boxes.view_any', ['branch_id'])->where('is_active', true)
                ->toBase()->groupBy('currency')->orderBy('currency')
                ->selectRaw('currency, SUM(current_balance) AS total')->pluck('total', 'currency')->map(fn ($v) => (string) $v)->all();

            $stats[] = $this->totalStat(__('Money in boxes and banks'), $balances)
                ->icon(Heroicon::OutlinedWallet)
                ->url(CashBoxResource::getUrl('index'));
        }

        if ($scope->hasAny($user, 'purchases.view_any')) {
            $owed = PartyLedgerEntry::query()->where('party_type', (new Supplier)->getMorphClass())
                ->toBase()->groupBy('currency')->orderBy('currency')
                ->selectRaw('currency, SUM(amount) AS total')->pluck('total', 'currency')
                ->map(fn ($v) => (string) $v)->filter(fn ($v) => ! BigDecimal::of($v)->isZero())->all();

            $stats[] = $this->totalStat(__('Owed to suppliers'), $owed)
                ->icon(Heroicon::OutlinedTruck)
                ->url(SupplierPaymentResource::getUrl('index'));

            $overdue = ServiceAction::scoped(Purchase::query(), 'purchases.view_any', ['branch_id'])
                ->where('status', PurchaseStatus::Confirmed)->where('payment_status', '<>', PaymentStatus::Paid)
                ->whereDate('due_date', '<', today())->count();

            $stats[] = Stat::make(__('Overdue supplier invoices'), Display::number($overdue))
                ->icon(Heroicon::OutlinedClock)
                ->color($overdue > 0 ? 'danger' : 'success')
                ->url(PurchaseResource::getUrl('index', ['filters' => ['overdue' => ['isActive' => true]]]));
        }

        $waiting = ApprovalResource::getEloquentQuery()->where('status', ApprovalStatus::Pending)->where('requested_by', '<>', $user->id)->count();

        if ($waiting > 0 || $user->can('viewAny', Approval::class)) {
            $stats[] = Stat::make(__('Waiting for your approval'), Display::number($waiting))
                ->icon(Heroicon::OutlinedHandRaised)
                ->color($waiting > 0 ? 'warning' : 'gray')
                ->url(ApprovalResource::getUrl('index'));
        }

        return $stats;
    }

    /**
     * @param  array<string, string>  $perCurrency
     */
    private function totalStat(string $label, array $perCurrency): Stat
    {
        $split = implode(' · ', array_map(fn ($currency, $value) => Display::money($value, (string) $currency), array_keys($perCurrency), $perCurrency));
        $snapshot = app(FxService::class)->current();

        if ($snapshot === null || $perCurrency === []) {
            return Stat::make($label, $perCurrency === [] ? Display::money('0', 'USD') : $split);
        }

        $converter = app(FxService::class)->converter($snapshot);
        $usd = array_reduce(array_keys($perCurrency), fn (BigDecimal $sum, $currency) => $sum->plus($converter->toUsd($perCurrency[$currency], (string) $currency)), BigDecimal::zero());

        return Stat::make($label, Display::money((string) $usd, 'USD'))->description(count($perCurrency) > 1 ? $split : null);
    }
}
