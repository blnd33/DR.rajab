<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Branch;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Models\UserSession;
use App\Support\Locale\Display;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class SystemOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Auth::user()?->can('dashboard.view') ?? false;
    }

    protected function getStats(): array
    {
        $failed = LoginAttempt::query()
            ->where('successful', false)
            ->where('created_at', '>=', now()->subDay())
            ->count();

        return [
            Stat::make(__('Open stations'), Display::number(Branch::query()->active()->count()))
                ->icon(Heroicon::OutlinedBuildingOffice2),
            Stat::make(__('Active users'), Display::number(User::query()->active()->count()))
                ->icon(Heroicon::OutlinedUsers),
            Stat::make(__('Devices active today'), Display::number(
                UserSession::query()->active()->where('last_activity_at', '>=', now()->startOfDay())->count()
            ))->icon(Heroicon::OutlinedDevicePhoneMobile),
            Stat::make(__('Failed logins (24 h)'), Display::number($failed))
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color($failed > 10 ? 'danger' : 'gray')
                ->description($failed > 10 ? __('Unusually high — check the activity log') : null),
        ];
    }
}
