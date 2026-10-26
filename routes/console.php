<?php

use App\Models\IdempotencyKey;
use App\Services\Assets\OverdueKitNotifier;
use App\Services\Expiry\ExpiryTransitionNotifier;
use App\Services\Stock\LowStockDetector;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduler (cron: * * * * * php artisan schedule:run)
|--------------------------------------------------------------------------
| Times are in the business timezone (Asia/Baghdad). Later phases add:
| EUR/USD rate fetch, recurring expenses, overdue kits / supplier invoices.
*/

$tz = config('orthotrack.timezone');

// Encrypted off-site backup (DB + uploads) at 02:00, cleanup at 03:00, health check at 08:00.
Schedule::command('backup:run')->dailyAt('02:00')->timezone($tz)->onOneServer()->withoutOverlapping();
Schedule::command('backup:clean')->dailyAt('03:00')->timezone($tz)->onOneServer();
Schedule::command('backup:monitor')->dailyAt('08:00')->timezone($tz)->onOneServer();

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

Schedule::command('stock:alerts')->dailyAt(config('orthotrack.stock_alerts_time'))->timezone($tz)->onOneServer()->withoutOverlapping();
