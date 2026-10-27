<?php

use App\Enums\Role;
use App\Models\IdempotencyKey;
use App\Models\User;
use App\Services\Assets\OverdueKitNotifier;
use App\Services\Expenses\RecurringExpenseService;
use App\Services\Expiry\ExpiryTransitionNotifier;
use App\Services\Health\Heartbeat;
use App\Services\Money\FxService;
use App\Services\Purchasing\OverduePayablesNotifier;
use App\Services\Reports\StockValueSnapshotter;
use App\Services\Settings\SettingsRepository;
use App\Services\Stock\LowStockDetector;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduler (cron: * * * * * php artisan schedule:run)
|--------------------------------------------------------------------------
| Times are in the business timezone (Asia/Baghdad).
*/

$tz = config('orthotrack.timezone');

// Encrypted off-site backup (DB + uploads) at 02:00, cleanup at 03:00, health check at 08:00.
Heartbeat::track(Schedule::command('backup:run')->dailyAt('02:00')->timezone($tz)->onOneServer()->withoutOverlapping(), 'backup:run');
Schedule::command('backup:clean')->dailyAt('03:00')->timezone($tz)->onOneServer();
Heartbeat::track(Schedule::command('backup:monitor')->dailyAt('08:00')->timezone($tz)->onOneServer(), 'backup:monitor');

// Horizon metrics.
Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Offline-sync replay protection is only needed for a while.
Schedule::call(fn () => IdempotencyKey::query()->where('created_at', '<', now()->subDays(30))->delete())
    ->daily()
    ->name('prune-idempotency-keys')
    ->onOneServer();

// Expired password reset tokens.
Schedule::command('auth:clear-resets')->daily();

/*
| Stock alerts (Phase 1): items entering a new expiry level, products below minimum;
| overdue kits (Phase 3).
| Run by hand: php artisan stock:alerts
*/
Artisan::command('stock:alerts {--no-notify : Update expiry levels without sending notifications}', function (ExpiryTransitionNotifier $expiry, LowStockDetector $lowStock, OverdueKitNotifier $overdueKits) {
    $entered = $expiry->run(notify: ! $this->option('no-notify'));
    $this->info('Expiry levels updated: '.array_sum(array_map('array_sum', $entered)).' items entered a new level.');

    if (! $this->option('no-notify')) {
        $this->info('Low-stock digests sent for '.$lowStock->notify().' branch(es).');
        $this->info('Overdue-kit reminders sent for '.$overdueKits->notify().' branch(es).');
    }
})->purpose('Daily expiry-level, low-stock and overdue-kit alerts');

Heartbeat::track(Schedule::command('stock:alerts')->dailyAt(config('orthotrack.stock_alerts_time'))->timezone($tz)->onOneServer()->withoutOverlapping(), 'stock:alerts');

/*
| Money (Phase 4): ECB EUR/USD suggestion (confirmed by a person unless auto-confirm is on),
| recurring expenses due today, overdue supplier invoice reminders.
| Run by hand: php artisan money:daily
*/
Artisan::command('money:daily {--no-notify : Do the work without sending reminders}', function (FxService $fx, SettingsRepository $settings, RecurringExpenseService $recurring, OverduePayablesNotifier $overdue) {
    if ($settings->bool('fx.eur_usd_auto_fetch')) {
        $rate = $fx->fetchEcb();
        $this->info($rate === null ? 'EUR/USD: nothing new from the ECB.' : 'EUR/USD suggested: '.$rate->rate);

        $approver = User::query()->whereHas('roles', fn ($q) => $q->where('name', Role::SuperAdmin->value))->where('is_active', true)->orderBy('id')->first();

        if ($rate !== null && $settings->bool('fx.eur_usd_auto_confirm') && $approver !== null) {
            $fx->confirm($rate, $approver);
            $this->info('EUR/USD confirmed automatically.');
        }
    }

    $this->info('Recurring expenses created: '.$recurring->generateDue());

    if (! $this->option('no-notify')) {
        $this->info('Overdue-invoice reminders sent for '.$overdue->notify().' branch(es).');
    }
})->purpose('Daily exchange rate, recurring expenses and overdue supplier invoice reminders');

Heartbeat::track(Schedule::command('money:daily')->dailyAt('07:00')->timezone($tz)->onOneServer()->withoutOverlapping(), 'money:daily');

/*
| Reports (Phase 5): stock value per branch and currency at the end of each day.
| Run by hand: php artisan reports:snapshot
*/
Artisan::command('reports:snapshot', function (StockValueSnapshotter $snapshotter) {
    $this->info('Stock value rows written: '.$snapshotter->snapshot());
})->purpose('Nightly stock value snapshot per branch and currency');

Heartbeat::track(Schedule::command('reports:snapshot')->dailyAt('23:50')->timezone($tz)->onOneServer()->withoutOverlapping(), 'reports:snapshot');
