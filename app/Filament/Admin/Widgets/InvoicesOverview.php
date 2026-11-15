<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\SalesInvoiceStatus;
use App\Filament\Admin\Resources\SalesInvoices\SalesInvoiceResource;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Money\FxService;
use App\Services\Sales\InvoiceDescriber;
use App\Support\Locale\Display;
use Brick\Math\BigDecimal;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * Paid and not paid (sales and surgeries): money received this month, money still owed to us,
 * and how many invoices wait. Totals in USD at today's rate, each currency underneath.
 */
class InvoicesOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('dashboard.view') && $user->can('viewAny', SalesInvoice::class);
    }

    protected function getHeading(): ?string
    {
        return __('Invoices: paid and not paid');
    }

    protected function getStats(): array
    {
        $monthStart = now(config('orthotrack.timezone'))->startOfMonth()->utc();

        $received = SalesInvoiceResource::getEloquentQuery()->where('status', SalesInvoiceStatus::Paid)->where('paid_at', '>=', $monthStart)
            ->groupBy('currency')->selectRaw('currency, SUM(total) AS amount')->toBase()->pluck('amount', 'currency')->map(fn ($v) => (string) $v)->all();

        $open = InvoiceDescriber::open(SalesInvoiceResource::getEloquentQuery());
        $owed = (clone $open)->groupBy('currency')->selectRaw('currency, SUM(total - paid_amount) AS amount')->toBase()->pluck('amount', 'currency')->map(fn ($v) => (string) $v)->all();
        $count = (clone $open)->count();
        $oldest = (clone $open)->min('issued_at');
        $url = SalesInvoiceResource::getUrl('index');

        return [
            $this->totalStat(__('Received this month'), $received)->icon(Heroicon::OutlinedCheckCircle)->color('success')->url($url.'?tab=paid'),
            $this->totalStat(__('Still owed to us'), $owed)->icon(Heroicon::OutlinedClock)->color($owed === [] ? 'gray' : 'warning')->url($url),
            Stat::make(__('Invoices not paid'), Display::number($count))
                ->description($oldest !== null ? __('Oldest waits :days days', ['days' => Display::number((int) now()->diffInDays($oldest, true))]) : __('Everything is paid.'))
                ->icon(Heroicon::OutlinedDocumentText)->url($url),
        ];
    }

    /**
     * @param  array<string, string>  $perCurrency
     */
    private function totalStat(string $label, array $perCurrency): Stat
    {
        $perCurrency = array_filter($perCurrency, fn ($v) => ! BigDecimal::of($v)->isZero());
        $split = implode(' · ', array_map(fn ($currency, $value) => Display::money($value, (string) $currency), array_keys($perCurrency), $perCurrency));
        $snapshot = app(FxService::class)->current();

        if ($perCurrency === [] || $snapshot === null) {
            return Stat::make($label, $perCurrency === [] ? Display::money('0', 'USD') : $split);
        }

        $converter = app(FxService::class)->converter($snapshot);
        $usd = array_reduce(array_keys($perCurrency), fn (BigDecimal $sum, $currency) => $sum->plus($converter->toUsd($perCurrency[$currency], (string) $currency)), BigDecimal::zero());

        return Stat::make($label, Display::money((string) $usd, 'USD'))->description(count($perCurrency) > 1 || array_key_first($perCurrency) !== 'USD' ? $split : null);
    }
}
