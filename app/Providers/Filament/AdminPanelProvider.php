<?php

namespace App\Providers\Filament;

use App\Enums\Panel as AppPanel;
use App\Filament\Admin\Auth\EditProfile;
use App\Filament\Admin\Auth\Login;
use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Support\KmAvatarProvider;
use App\Http\Middleware\AutoLoginOnTestSite;
use App\Http\Middleware\FilamentAuthenticate;
use App\Http\Middleware\FocusAdminBranch;
use App\Http\Middleware\NormalizeDigits;
use App\Http\Middleware\RequireAdminMfa;
use App\Http\Middleware\SendGuestsToMainCompany;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TrackUserSession;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use App\Support\Locale\LocaleRegistry;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            ->profile(EditProfile::class, isSimple: false)
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable(),
            ])
            ->brandName(fn () => app(SettingsRepository::class)->get('company.name', config('app.name')))
            ->colors([
                'primary' => Color::hex('#0E7490'),
                'gray' => Color::Slate,
                'danger' => Color::Red,
                'warning' => Color::Amber,
                'success' => Color::Green,
                'info' => Color::Blue,
            ])
            // Self-hosted fonts (bundled in the theme) — no external font CDN.
            ->font(
                fn () => app(LocaleRegistry::class)->isRtl() ? 'Vazirmatn Variable' : 'Inter Variable',
                provider: LocalFontProvider::class,
            )
            ->monoFont('JetBrains Mono Variable', provider: LocalFontProvider::class)
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->favicon(asset('icons/favicon.svg'))
            ->defaultAvatarProvider(KmAvatarProvider::class)
            ->sidebarCollapsibleOnDesktop()
            // One search only: "Search the whole system" on the Main page (not the top-bar search).
            ->globalSearch(false)
            ->databaseNotifications()
            ->unsavedChangesAlerts()
            ->navigationGroups([
                NavigationGroup::make(fn () => __('Insight'))->collapsible(),
                NavigationGroup::make(fn () => __('Clinical'))->collapsible(),
                NavigationGroup::make(fn () => __('Operations'))->collapsible(),
                NavigationGroup::make(fn () => __('Stock'))->collapsible(),
                // Two sections of their own under Stock.
                NavigationGroup::make(fn () => __('Pricelist'))->collapsible(),
                NavigationGroup::make(fn () => __('Catalogues'))->collapsible(),
                NavigationGroup::make(fn () => __('Catalog'))->collapsible(),
                NavigationGroup::make(fn () => __('Finance'))->collapsible(),
                NavigationGroup::make(fn () => __('Company agents'))->collapsible(),
                NavigationGroup::make(fn () => __('Official company documents'))->collapsible(),
                NavigationGroup::make(fn () => __('Supplier company documents'))->collapsible(),
                NavigationGroup::make(fn () => __('Shipment documents'))->collapsible(),
                NavigationGroup::make(fn () => __('Administration'))->collapsible(),
                NavigationGroup::make(fn () => __('System'))->collapsible(),
            ])
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\Filament\Admin\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\Filament\Admin\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\Filament\Admin\Widgets')
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn () => Blade::render('<x-pwa-head /><x-nav-title-case />'),
            )
            ->renderHook(
                PanelsRenderHook::BODY_START,
                fn () => Blade::render('<x-test-site-banner />'),
            )
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE,
                fn () => Blade::render('<x-auth-notice /><x-language-switcher class="mb-2" />'),
            )
            // The other company (KurdishMedicus ↔ Al-Fadhaa' Bureau), right after the company name.
            ->renderHook(
                PanelsRenderHook::TOPBAR_LOGO_AFTER,
                fn () => Blade::render('<x-company-switcher />'),
            )
            // Language can be changed from every page, not only before logging in.
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                fn () => Blade::render('<div class="flex items-center gap-2 me-2"><x-branch-switcher /><x-language-switcher variant="compact" /></div>'),
            )
            ->userMenuItems([
                // Panel switcher for users who can use both front-ends.
                Action::make('ops-app')
                    ->label(fn () => __('Open operations app'))
                    ->icon(Heroicon::OutlinedDevicePhoneMobile)
                    ->url(fn () => AppPanel::Ops->homeUrl())
                    ->visible(fn () => Auth::user() instanceof User && Auth::user()->canAccessAppPanel(AppPanel::Ops)),
            ])
            ->renderHook(
                PanelsRenderHook::FOOTER,
                fn () => Blade::render('<x-powered-by class="py-4" />'),
            )
            ->renderHook(
                PanelsRenderHook::SCRIPTS_AFTER,
                fn () => Blade::render('@vite(\'resources/js/admin.js\')'),
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                // Test site only (ORTHOTRACK_AUTO_LOGIN): sign visitors in automatically.
                AutoLoginOnTestSite::class,
                // Al-Fadhaa' Bureau has no login of its own: guests go back to KurdishMedicus.
                SendGuestsToMainCompany::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                SetLocale::class,
                NormalizeDigits::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                FilamentAuthenticate::class,
                TrackUserSession::class,
                RequireAdminMfa::class,
            ])
            // Also on Livewire updates of admin pages, so tables and forms follow the branch.
            ->authMiddleware([FocusAdminBranch::class], isPersistent: true);
    }
}
