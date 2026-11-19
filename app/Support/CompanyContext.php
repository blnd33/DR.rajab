<?php

namespace App\Support;

use App\Enums\Company;
use App\Services\Settings\SettingsRepository;
use Illuminate\Contracts\Foundation\Application;
use Spatie\Permission\PermissionRegistrar;

/**
 * Two companies in one system (same owner, same code, separate data): each company has its own
 * database — its own staff, stations, stock, money, documents and settings — and its own session,
 * cache, uploaded files and backups. KurdishMedicus uses the main database (DB_DATABASE); another
 * company is switched on by giving it a database (config orthotrack.companies.<company>.database).
 *
 * Which company a request works in:
 *  - web: the cookie set by the top-bar switch (CompanySwitchController);
 *  - command line / cron: ORTHOTRACK_COMPANY=al_fadhaa php artisan …
 *
 * It is chosen while the application starts (AppServiceProvider::register), before anything uses
 * the database, so nothing from one company can reach the other. Signing in is per company: the
 * other company asks to sign in again (its staff are its own).
 */
final class CompanyContext
{
    public const COOKIE = 'orthotrack_company';

    public const ENV = 'ORTHOTRACK_COMPANY';

    private static Company $current = Company::KurdishMedicus;

    /** The main company's settings, to go back to (tests and runtime switches). */
    private static ?array $base = null;

    /** The application those settings belong to. */
    private static ?int $app = null;

    public static function current(): Company
    {
        return self::$current;
    }

    /**
     * The main company, then every company that has a database.
     *
     * @return list<Company>
     */
    public static function available(): array
    {
        return array_values(array_filter(Company::cases(), self::isAvailable(...)));
    }

    public static function isAvailable(Company $company): bool
    {
        return $company->isMain() || filled(config("orthotrack.companies.{$company->value}.database"));
    }

    /**
     * While the application starts: the company of this request or command (config only).
     */
    public static function boot(Application $app): void
    {
        // A new application (each request; each test) starts from its own main settings.
        if (self::$app !== spl_object_id($app)) {
            self::$app = spl_object_id($app);
            self::$base = null;
        } else {
            self::configure(Company::main());
        }

        self::$current = Company::main();

        $value = $app->runningInConsole()
            ? getenv(self::ENV)
            : ($app->bound('request') ? $app['request']->cookies->get(self::COOKIE) : null);

        $company = Company::tryFrom((string) $value);

        if ($company !== null && ! $company->isMain() && self::isAvailable($company)) {
            self::configure($company);
        }
    }

    /**
     * Change company in a running process (tests, tools): the settings and everything already
     * opened with the old ones (database, cache, files, permissions, sign-in).
     */
    public static function activate(Company $company): void
    {
        self::configure($company);

        app('cache')->forgetDriver();
        app('filesystem')->forgetDisk(['local', 'public']);
        app('auth')->forgetGuards();
        app()->forgetScopedInstances();
        app()->forgetInstance(SettingsRepository::class);

        $permissions = app(PermissionRegistrar::class);
        $permissions->initializeCache();
        $permissions->clearPermissionsCollection();
    }

    /**
     * Run something in another company and come back.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(Company $company, callable $callback): mixed
    {
        $previous = self::$current;
        self::activate($company);

        try {
            return $callback();
        } finally {
            self::activate($previous);
        }
    }

    /**
     * The database connection name of a company.
     */
    public static function connection(Company $company): string
    {
        self::remember();

        return $company->isMain() ? self::$base['database.default'] : 'company_'.$company->value;
    }

    private static function configure(Company $company): void
    {
        self::remember();
        $base = self::$base;

        if ($company->isMain()) {
            config($base);
            self::$current = $company;

            return;
        }

        $suffix = $company->value;
        $connection = self::connection($company);

        // Same server and login as the main database, its own database name (unless set in full).
        if (! is_array(config("database.connections.{$connection}"))) {
            config(["database.connections.{$connection}" => array_merge(
                (array) config('database.connections.'.$base['database.default']),
                ['database' => config("orthotrack.companies.{$suffix}.database"), 'url' => null],
            )]);
        }

        config([
            'database.default' => $connection,
            'session.cookie' => $base['session.cookie'].'-'.$suffix,
            'session.connection' => null,
            'cache.prefix' => $base['cache.prefix'].$suffix.'-',
            // The file cache ignores the prefix: its own folder.
            'cache.stores.file.path' => $base['cache.stores.file.path'].'/companies/'.$suffix,
            'cache.stores.file.lock_path' => $base['cache.stores.file.lock_path'].'/companies/'.$suffix,
            'filesystems.disks.local.root' => $base['filesystems.disks.local.root'].'/companies/'.$suffix,
            'filesystems.disks.public.root' => $base['filesystems.disks.public.root'].'/companies/'.$suffix,
            'filesystems.disks.public.url' => $base['filesystems.disks.public.url'].'/companies/'.$suffix,
            'backup.backup.name' => $base['backup.backup.name'].'-'.$suffix,
            'backup.backup.source.databases' => $base['backup.backup.source.databases'] === [] ? [] : [$connection],
            'backup.monitor_backups.0.name' => $base['backup.backup.name'].'-'.$suffix,
            'permission.cache.key' => $base['permission.cache.key'].'.'.$suffix,
        ]);

        self::$current = $company;
    }

    private static function remember(): void
    {
        if (self::$base !== null) {
            return;
        }

        $keys = [
            'database.default', 'session.cookie', 'session.connection', 'cache.prefix',
            'cache.stores.file.path', 'cache.stores.file.lock_path',
            'filesystems.disks.local.root', 'filesystems.disks.public.root', 'filesystems.disks.public.url',
            'backup.backup.name', 'backup.backup.source.databases', 'backup.monitor_backups.0.name',
            'permission.cache.key',
        ];

        self::$base = array_combine($keys, array_map(fn (string $key) => config($key), $keys));
    }
}
