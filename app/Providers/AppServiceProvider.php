<?php

namespace App\Providers;

use App\Http\Responses\LoginResponse;
use App\Http\Responses\LogoutResponse;
use App\Models\Activity;
use App\Models\User;
use App\Policies\RolePolicy;
use App\Services\Auth\LoginAttemptLogger;
use App\Services\Expiry\ExpiryClassifier;
use App\Services\Permissions\BranchScopeResolver;
use App\Services\Settings\SettingsRepository;
use App\Services\Stock\AlertRecipients;
use App\Support\Locale\DigitConverter;
use App\Support\Locale\LocaleRegistry;
use App\Support\Locale\NumberLocale;
use BladeUI\Icons\Factory as IconFactory;
use Filament\Auth\Http\Responses\Contracts\LoginResponse as LoginResponseContract;
use Filament\Auth\Http\Responses\Contracts\LogoutResponse as LogoutResponseContract;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;
use Spatie\Translatable\Facades\Translatable;

use function Livewire\on;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsRepository::class);
        $this->app->singleton(LocaleRegistry::class);
        // Memoised per request (and per Octane request if ever used).
        $this->app->scoped(BranchScopeResolver::class);
        $this->app->scoped(ExpiryClassifier::class);
        $this->app->scoped(AlertRecipients::class);

        $this->app->bind(LoginResponseContract::class, LoginResponse::class);
        $this->app->bind(LogoutResponseContract::class, LogoutResponse::class);

        // Our own icons (resources/svg), e.g. @svg('ot-barcode').
        $this->callAfterResolving(IconFactory::class, fn (IconFactory $icons) => $icons->add('orthotrack', ['path' => resource_path('svg'), 'prefix' => 'ot']));
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configureAuthorization();
        $this->configureLocalization();
        $this->configureSecurity();
        $this->configureFilamentFormatting();

        Event::subscribe(LoginAttemptLogger::class);

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }
    }

    private function configureModels(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
        Model::automaticallyEagerLoadRelationships();
    }

    private function configureAuthorization(): void
    {
        // Super Admin can do everything (business rules in services still apply) — except
        // model abilities that are structurally forbidden: deletes, and anything on the
        // read-only activity log. Those still go through their policy.
        Gate::before(function (User $user, string $ability, array $arguments) {
            // Technicians hold no permissions, whatever is attached to the account.
            if ($user->isTechnician()) {
                return false;
            }

            if (! $user->is_active || ! $user->isSuperAdmin()) {
                return null;
            }

            $structural = in_array($ability, ['delete', 'deleteAny', 'forceDelete', 'forceDeleteAny', 'restore', 'restoreAny'], true)
                || (($arguments[0] ?? null) instanceof Activity)
                || ($arguments[0] ?? null) === Activity::class;

            return $structural ? null : true;
        });

        Gate::policy(Role::class, RolePolicy::class);
    }

    private function configureLocalization(): void
    {
        // Translatable DB fields: requested locale → en → any filled locale.
        Translatable::fallback(fallbackLocale: 'en', fallbackAny: true);

        // Livewire updates: normalise Arabic-Indic/Persian digits before validation.
        on('request', fn () => fn (array $components) => DigitConverter::normalizeLivewirePayload($components));
    }

    private function configureSecurity(): void
    {
        Password::defaults(function () {
            $rule = Password::min(10)->letters()->mixedCase()->numbers();

            // The breach check calls an external API; only enforce it in production.
            return $this->app->isProduction() ? $rule->uncompromised(3) : $rule;
        });

        RateLimiter::for('sync', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));
    }

    private function configureFilamentFormatting(): void
    {
        $dateFormat = config('orthotrack.date_format');
        $dateTimeFormat = config('orthotrack.datetime_format');

        Table::configureUsing(fn (Table $table) => $table
            ->defaultDateDisplayFormat($dateFormat)
            ->defaultDateTimeDisplayFormat($dateTimeFormat)
            ->defaultNumberLocale(fn () => NumberLocale::current())
            ->defaultPaginationPageOption(25));

        Schema::configureUsing(fn (Schema $schema) => $schema
            ->defaultDateDisplayFormat($dateFormat)
            ->defaultDateTimeDisplayFormat($dateTimeFormat)
            ->defaultNumberLocale(fn () => NumberLocale::current()));

        // Digit-aware dates and LTR codes: see App\Filament\Admin\Tables\Columns\{LocalDateTimeColumn, CodeColumn}.
    }
}
