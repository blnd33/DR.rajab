<?php

use App\Models\IdempotencyKey;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduler (cron: * * * * * php artisan schedule:run)
|--------------------------------------------------------------------------
| Times are in the business timezone (Asia/Baghdad). Later phases add:
| expiry-level sweep, low-stock sweep, EUR/USD rate fetch, recurring
| expenses, overdue kits / supplier invoices.
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
