<?php

namespace App\Filament\Admin\Pages;

use App\Enums\DigitSystem;
use App\Services\Settings\SettingsRepository;
use App\Support\Locale\LocaleRegistry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * System settings (Module 29). Later phases add tabs here (expiry levels, lists, rate sources…).
 *
 * @property-read Schema $form
 */
class Settings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = 80;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('System');
    }

    public static function getNavigationLabel(): string
    {
        return __('Settings');
    }

    public function getTitle(): string
    {
        return __('Settings');
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->can('settings.manage') ?? false;
    }

    public function mount(SettingsRepository $settings): void
    {
        $state = [];

        foreach (array_keys($settings->defaults()) as $key) {
            data_set($state, $key, $settings->get($key));
        }

        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        $locales = app(LocaleRegistry::class);

        return $schema
            ->statePath('data')
            ->components([
                Tabs::make('settings')
                    ->persistTabInQueryString()
                    ->tabs([
                        Tab::make(__('Company'))
                            ->icon(Heroicon::OutlinedBuildingOffice)
                            ->columns(['default' => 1, 'md' => 2])
                            ->schema([
                                TextInput::make('company.name')->label(__('Display name'))->required()->maxLength(120),
                                TextInput::make('company.legal_name')->label(__('Legal name'))->maxLength(191),
                                TextInput::make('company.phone')->label(__('Phone'))->tel()->extraInputAttributes(['dir' => 'ltr']),
                                TextInput::make('company.email')->label(__('Email'))->email()->extraInputAttributes(['dir' => 'ltr']),
                                Textarea::make('company.address')->label(__('Address'))->rows(2)->columnSpanFull(),
                                FileUpload::make('company.logo_path')
                                    ->label(__('Logo'))
                                    ->helperText(__('Used on printed documents and the app header. PNG or SVG.'))
                                    ->image()
                                    ->disk('public')
                                    ->directory('branding')
                                    ->maxSize(1024)
                                    ->columnSpanFull(),
                            ]),

                        Tab::make(__('Languages & numbers'))
                            ->icon(Heroicon::OutlinedLanguage)
                            ->schema([
                                Section::make(__('Digits shown per language'))
                                    ->description(__('Display only. Data is always stored with Western digits; codes (serials, lots, SKUs, phone and document numbers) always stay Western.'))
                                    ->columns(['default' => 1, 'md' => 3])
                                    ->schema(collect($locales->all())->map(
                                        fn (array $locale, string $code) => Select::make("locale.digits.{$code}")
                                            ->label($locale['native'])
                                            ->options(DigitSystem::options())
                                            ->required()
                                            ->native(false)
                                    )->values()->all()),
                            ]),

                        Tab::make(__('Security'))
                            ->icon(Heroicon::OutlinedLockClosed)
                            ->columns(['default' => 1, 'md' => 2])
                            ->schema([
                                TextInput::make('security.session_timeout_minutes')
                                    ->label(__('Log out after inactivity (minutes)'))
                                    ->helperText(__('Default for all users; can be shortened per user for shared devices.'))
                                    ->numeric()->minValue(5)->maxValue(10080)->required(),
                                TextInput::make('security.max_login_attempts')
                                    ->label(__('Login attempts before a short lock'))
                                    ->numeric()->minValue(3)->maxValue(20)->required(),
                                Toggle::make('security.require_admin_mfa')
                                    ->label(__('Super Admin must use two-factor authentication'))
                                    ->columnSpanFull(),
                            ]),

                        Tab::make(__('Approvals'))
                            ->icon(Heroicon::OutlinedCheckBadge)
                            ->columns(['default' => 1, 'md' => 2])
                            ->schema([
                                TextInput::make('approvals.supplier_payment_threshold_usd')
                                    ->label(__('Supplier payments above (USD) need approval'))
                                    ->numeric()->minValue(0)->required(),
                                TextInput::make('approvals.expense_threshold_usd')
                                    ->label(__('Expenses above (USD) need approval'))
                                    ->numeric()->minValue(0)->required(),
                            ]),

                        Tab::make(__('Kits'))
                            ->icon(Heroicon::OutlinedBriefcase)
                            ->schema([
                                TextInput::make('kits.overdue_days')
                                    ->label(__('A kit is overdue after (days at hospital)'))
                                    ->numeric()->minValue(1)->maxValue(365)->required(),
                            ]),

                        Tab::make(__('Exchange rates'))
                            ->icon(Heroicon::OutlinedCurrencyDollar)
                            ->schema([
                                Toggle::make('fx.eur_usd_auto_fetch')->label(__('Fetch EUR/USD automatically every day (as a suggestion)')),
                                Toggle::make('fx.eur_usd_auto_confirm')->label(__('Use the fetched EUR/USD rate without manual confirmation')),
                            ]),

                        Tab::make(__('Optional features'))
                            ->icon(Heroicon::OutlinedPuzzlePiece)
                            ->schema([
                                Toggle::make('features.credit_sales')->label(__('Credit sales to hospitals (receivables)')),
                                Toggle::make('features.price_lists')->label(__('Price lists per hospital')),
                                Toggle::make('features.commissions')->label(__('Doctor commissions')),
                            ]),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label(__('Save settings'))->submit('save')->keyBindings(['mod+s']),
                    ]),
                ]),
        ]);
    }

    public function save(SettingsRepository $settings): void
    {
        $state = $this->form->getState();
        $values = [];

        foreach (array_keys($settings->defaults()) as $key) {
            if (data_get($state, $key, '__missing__') !== '__missing__') {
                $values[$key] = data_get($state, $key);
            }
        }

        $settings->setMany($values);

        Notification::make()->title(__('Settings saved.'))->success()->send();
    }
}
