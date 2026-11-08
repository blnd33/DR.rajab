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
use App\Services\Users\UserService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

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

// Horizon metrics (only where Horizon runs the queue on Redis).
if (config('queue.default') === 'redis') {
    Schedule::command('horizon:snapshot')->everyFiveMinutes();
}

// Shared hosting: no Supervisor, so each minute's scheduler run works the queue for up to
// 55 seconds. A long import keeps its worker; the lock lets another start after 10 minutes.
if (config('orthotrack.queue_via_scheduler')) {
    Schedule::command('queue:work --stop-when-empty --max-time=55 --memory=512')
        ->everyMinute()
        ->withoutOverlapping(10)
        ->runInBackground();
}

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

/*
| New servers and the client's test site (docs/TEST-SITE.md).
|   php artisan orthotrack:create-admin rajab --name="Dr. Rajab"
|   php artisan orthotrack:reset-test-site      (test site only: erases everything)
*/
Artisan::command('orthotrack:create-admin {username : Login name (a-z, 0-9, dot, dash, underscore)} {--name= : Full name} {--email= : Optional email} {--locale=en : en, ar or ckb}', function (UserService $users) {
    $input = [
        'username' => strtolower((string) $this->argument('username')),
        'name' => (string) ($this->option('name') ?: $this->argument('username')),
        'email' => $this->option('email') ?: null,
        'locale' => (string) $this->option('locale'),
    ];

    $rules = Validator::make($input, [
        'username' => ['required', 'regex:/^[a-z0-9._-]{3,64}$/', Rule::unique('users', 'username')],
        'name' => ['required', 'string', 'max:150'],
        'email' => ['nullable', 'email', Rule::unique('users', 'email')],
        'locale' => ['required', Rule::in(['en', 'ar', 'ckb'])],
    ]);

    if ($rules->fails()) {
        foreach ($rules->errors()->all() as $message) {
            $this->error($message);
        }

        return 1;
    }

    $password = (string) $this->secret('Password (at least 10 characters, upper and lower case letters and a number)');
    $check = Validator::make(
        ['password' => $password, 'password_confirmation' => (string) $this->secret('Password again')],
        ['password' => ['required', 'confirmed', Password::defaults()]],
    );

    if ($check->fails()) {
        foreach ($check->errors()->all() as $message) {
            $this->error($message);
        }

        return 1;
    }

    $users->create($input + [
        'password' => $password,
        'all_branches' => true,
        'primary_branch_id' => null,
        'is_active' => true,
        'roles' => [Role::SuperAdmin->value],
        'branches' => [],
    ]);

    $this->info("Super Admin \"{$input['username']}\" created. Sign in at ".url('/admin').'.');

    return 0;
})->purpose('Create a Super Admin login (the first login on a new or emptied site)');

Artisan::command('orthotrack:reset-test-site {--force : Do not ask to type the site name}', function () {
    if (! config('orthotrack.test_site')) {
        $this->error('Refused: this is not a test site. Only a site with ORTHOTRACK_TEST_SITE=true in .env can be emptied.');

        return 1;
    }

    $site = (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: config('app.url'));

    if (! $this->option('force') && $this->ask("This erases ALL data and uploaded files on {$site}. Type {$site} to confirm") !== $site) {
        $this->warn('Nothing was erased.');

        return 1;
    }

    $this->call('migrate:fresh', ['--force' => true]);

    foreach (DatabaseSeeder::BASE as $seeder) {
        $this->call('db:seed', ['--class' => $seeder, '--force' => true]);
    }

    // Uploaded photos, invoices and import files belong to the erased data.
    $files = Storage::disk('local');
    foreach ($files->directories() as $directory) {
        $files->deleteDirectory($directory);
    }

    $this->call('cache:clear');
    $this->info("{$site} is empty again. Create the first login: php artisan orthotrack:create-admin <username>");

    return 0;
})->purpose('Empty the client\'s test site (refused on any other site)');
