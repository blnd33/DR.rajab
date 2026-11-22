<?php

use App\Enums\Role;
use App\Models\IdempotencyKey;
use App\Models\User;
use App\Services\Assets\OverdueKitNotifier;
use App\Services\Catalog\ClearItemsService;
use App\Services\Catalog\SampleDataService;
use App\Services\Catalog\TestPricingService;
use App\Services\Companies\CompanySetupService;
use App\Services\Expenses\RecurringExpenseService;
use App\Services\Expiry\ExpiryTransitionNotifier;
use App\Services\Health\Heartbeat;
use App\Services\Insight\MorningSummaryService;
use App\Services\Money\FxService;
use App\Services\Purchasing\OverduePayablesNotifier;
use App\Services\Reports\StockValueSnapshotter;
use App\Services\Settings\SettingsRepository;
use App\Services\Stock\LowStockDetector;
use App\Services\Users\UserService;
use App\Support\CompanyContext;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
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
    // Every queue the app uses (same list as Horizon): imports run on "imports", not "default".
    Schedule::command('queue:work --queue=high,default,imports,pdf --stop-when-empty --max-time=55 --memory=512')
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
| Morning summary (Phase 6): every 15 minutes, each person whose time has come gets today's
| summary once (in-app + phone push). Run by hand: php artisan summary:send
|   --user=admin   one person now, even if already sent today (to try it)
*/
Artisan::command('summary:send {--user= : Username: send this person\'s summary now}', function (MorningSummaryService $summaries) {
    if (filled($this->option('user'))) {
        $user = User::query()->where('username', (string) $this->option('user'))->first();

        if ($user === null || ! MorningSummaryService::canReceive($user)) {
            $this->error('No such active user with the "Receive the morning summary" permission.');

            return 1;
        }

        $summaries->send($user);
        $this->info('Morning summary sent to '.$user->username.'.');

        return 0;
    }

    $this->info('Morning summaries sent: '.$summaries->sendDue());

    return 0;
})->purpose('Send the morning summaries that are due');

Heartbeat::track(Schedule::command('summary:send')->everyFifteenMinutes()->onOneServer()->withoutOverlapping(), 'summary:send');

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

/*
| Test prices (test site): every product that has stock, in SKU order, costs 1, 2, 3… USD; its
| selling price is the cost + 20 %; the pieces in stock get the same cost (a stock correction
| with a reason, on the ledger). Shows a preview and asks first.
|   php artisan prices:test-fill --dry-run
|   php artisan prices:test-fill
*/
Artisan::command('prices:test-fill {--user= : Username who makes the change (default: the first super admin)} {--start=1 : Cost of the first product (USD)} {--step=1 : Added for each next product (USD)} {--markup=20 : Selling price = cost + this %} {--dry-run : Only show what would change} {--force : Do not ask}', function (TestPricingService $pricing) {
    $start = (int) $this->option('start');
    $step = (int) $this->option('step');
    $markup = (string) $this->option('markup');
    $plan = $pricing->plan($start, $step, $markup);

    if ($plan->isEmpty()) {
        $this->warn('No product has stock: nothing to price.');

        return 0;
    }

    $this->table(['#', 'SKU', 'Product', 'Cost USD', 'Selling USD'], $plan->take(15)->values()->map(fn (array $r, int $i) => [
        $i + 1, $r['product']->sku, mb_substr((string) $r['product']->name, 0, 40), number_format((float) $r['cost'], 2), number_format((float) $r['selling'], 2),
    ])->all());

    $last = $plan->last();
    $this->line(sprintf('%d products with stock; the last (%s) costs %s USD and sells for %s USD.', $plan->count(), $last['product']->sku, number_format((float) $last['cost'], 2), number_format((float) $last['selling'], 2)));

    if ($this->option('dry-run')) {
        $this->info('Dry run: nothing was changed.');

        return 0;
    }

    $username = $this->option('user');
    $user = $username
        ? User::query()->where('username', $username)->first()
        : User::query()->where('is_active', true)->whereHas('roles', fn ($q) => $q->where('name', Role::SuperAdmin->value))->orderBy('id')->first();

    if ($user === null) {
        $this->error('No such user (use --user=<username> of a super admin).');

        return 1;
    }

    if (! $this->option('force') && ! $this->confirm("Change these prices and the cost of the pieces in stock, as {$user->username}?")) {
        $this->line('Nothing was changed.');

        return 0;
    }

    $bar = $this->output->createProgressBar($plan->count());
    $summary = $pricing->apply($user, $start, $step, $markup, fn () => $bar->advance());
    $bar->finish();
    $this->newLine(2);

    $this->info(sprintf('Done: %d products priced, %d serial pieces and %d lots re-costed.', $summary['products'], $summary['units'], $summary['lots']));

    if ($summary['skipped'] > 0) {
        $this->warn(sprintf('%d pieces kept their cost (landed costs in another currency).', $summary['skipped']));
    }

    return 0;
})->purpose('Test prices: cost 1, 2, 3… USD by SKU for products in stock, selling price = cost + 20 %');

/*
| Sample data for the test site, from the supplier's workbook (Mikron / Lima):
| stations in five governorates, the workbook's products, stock, and test prices (1, 2, 3… USD, +20 %).
|   php artisan orthotrack:sample-data /path/to/IT_MIKRON_Trauma_items.xlsx
*/
Artisan::command('orthotrack:sample-data {file : The supplier workbook (.xlsx)} {--products=60 : How many products to put in stock} {--user= : Username who makes the changes (default: the first super admin)} {--force : Do not ask}', function (SampleDataService $sample) {
    if (! SampleDataService::allowed()) {
        $this->error('Sample data is only for a test site (set ORTHOTRACK_TEST_SITE=true).');

        return 1;
    }

    $username = $this->option('user');
    $user = $username
        ? User::query()->where('username', $username)->first()
        : User::query()->where('is_active', true)->whereHas('roles', fn ($q) => $q->where('name', Role::SuperAdmin->value))->orderBy('id')->first();

    if ($user === null) {
        $this->error('No such user (use --user=<username> of a super admin).');

        return 1;
    }

    if (! $this->option('force') && ! $this->confirm("Add sample stations, the workbook's products, stock and test prices, as {$user->username}?")) {
        $this->line('Nothing was changed.');

        return 0;
    }

    try {
        $sample->run((string) $this->argument('file'), $user, max(1, (int) $this->option('products')), fn (string $line) => $this->line($line));
    } catch (RuntimeException $e) {
        $this->error($e->getMessage());

        return 1;
    }

    $this->info('Sample data is ready.');

    return 0;
})->purpose('Test site: stations, products from the supplier workbook, stock and test prices');

/*
| Empty the Items list on the test site (App\Services\Catalog\ClearItemsService): the items, all
| their stock and every document made of item lines. Stations, users, customers, suppliers,
| patients, kits, cash boxes and money movements stay. Shows what goes and asks first.
|   php artisan orthotrack:clear-items --dry-run
|   php artisan orthotrack:clear-items
*/
Artisan::command('orthotrack:clear-items {--user= : Username recorded as who did it (default: the first super admin)} {--dry-run : Only show what would be removed} {--force : Do not ask to type the site name}', function (ClearItemsService $clear) {
    if (! ClearItemsService::allowed()) {
        $this->error('Refused: this is not a test site. Only a site with ORTHOTRACK_TEST_SITE=true in .env can have its items cleared.');

        return 1;
    }

    $preview = $clear->preview();

    if ($preview === []) {
        $this->info('The Items list is already empty. Nothing to remove.');

        return 0;
    }

    $this->table(['Table', 'Rows to remove'], collect($preview)->map(fn (int $n, string $table) => [$table, number_format($n)])->values()->all());

    if ($this->option('dry-run')) {
        $this->line('Dry run: nothing was removed.');

        return 0;
    }

    $username = $this->option('user');
    $user = $username
        ? User::query()->where('username', $username)->first()
        : User::query()->where('is_active', true)->whereHas('roles', fn ($q) => $q->where('name', Role::SuperAdmin->value))->orderBy('id')->first();

    if ($user === null) {
        $this->error('No such user (use --user=<username> of a super admin).');

        return 1;
    }

    $site = (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: config('app.url'));

    if (! $this->option('force') && $this->ask("This removes every item, all stock and the documents above from {$site} (stations, users, customers, suppliers and money stay). Type {$site} to confirm") !== $site) {
        $this->warn('Nothing was removed.');

        return 1;
    }

    $removed = $clear->run($user);

    $this->call('cache:clear');
    $this->info(sprintf('Items list cleared on %s: %s rows removed from %d tables.', $site, number_format(array_sum($removed)), count($removed)));

    return 0;
})->purpose('Test site: remove every item, its stock and the documents made of item lines');

/*
| Companies (same system, separate data — App\Support\CompanyContext). Commands work in the main
| company unless ORTHOTRACK_COMPANY names another:
|   ORTHOTRACK_COMPANY=al_fadhaa php artisan orthotrack:setup-company
|   php artisan orthotrack:migrate-all
*/
Artisan::command('orthotrack:setup-company', function (CompanySetupService $setup) {
    $company = CompanyContext::current();
    $asked = (string) getenv(CompanyContext::ENV);

    // Asked for another company that has no database: never fall back to KurdishMedicus silently.
    if ($asked !== '' && $asked !== $company->value) {
        $this->error("ORTHOTRACK_COMPANY={$asked} is set, but that company has no database: add ORTHOTRACK_AL_FADHAA_DATABASE to .env (on its own line), then php artisan optimize.");

        return 1;
    }
    $this->info('Company: '.$company->getLabel().' (database '.config('database.connections.'.config('database.default').'.database').')');

    $setup->setup(fn (string $line) => $this->line($line));

    $this->info($company->isMain()
        ? 'Ready.'
        : 'Ready. It has no login of its own: open it from KurdishMedicus with the button next to the company name.');

    return 0;
})->purpose('Prepare the current company\'s database: tables, base data, its name and stations');

Artisan::command('orthotrack:migrate-all', function () {
    $failed = false;

    foreach (CompanyContext::available() as $company) {
        $this->info($company->getLabel().':');
        $result = Process::env([CompanyContext::ENV => $company->value])
            ->timeout(600)
            ->run([PHP_BINARY, base_path('artisan'), 'migrate', '--force']);

        $this->line(trim($result->output().$result->errorOutput()));
        $failed = $failed || $result->failed();
    }

    return $failed ? 1 : 0;
})->purpose('Run the database migrations for every company');
