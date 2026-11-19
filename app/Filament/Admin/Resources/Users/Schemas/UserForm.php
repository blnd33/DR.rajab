<?php

namespace App\Filament\Admin\Resources\Users\Schemas;

use App\Enums\Role as RoleEnum;
use App\Models\Branch;
use App\Models\User;
use App\Services\Permissions\RoleService;
use App\Services\Settings\SettingsRepository;
use App\Support\Locale\LocaleRegistry;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 2])
            ->components([
                Section::make(__('Profile'))
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Full name'))
                            ->required()
                            ->maxLength(150),

                        TextInput::make('username')
                            ->label(__('Username'))
                            ->helperText(__('Lowercase letters, digits, dot, dash or underscore.'))
                            ->required()
                            ->minLength(3)
                            ->maxLength(64)
                            ->regex('/^[A-Za-z0-9._-]+$/')
                            ->unique(ignoreRecord: true)
                            ->extraInputAttributes(['dir' => 'ltr', 'autocapitalize' => 'none', 'spellcheck' => 'false']),

                        TextInput::make('email')
                            ->label(__('Email'))
                            ->email()
                            ->maxLength(191)
                            ->unique(ignoreRecord: true)
                            ->extraInputAttributes(['dir' => 'ltr']),

                        TextInput::make('phone')
                            ->label(__('Phone'))
                            ->tel()
                            ->maxLength(32)
                            ->extraInputAttributes(['dir' => 'ltr']),

                        Select::make('locale')
                            ->label(__('Language'))
                            ->options(fn () => app(LocaleRegistry::class)->options())
                            ->default('en')
                            ->required()
                            ->native(false),
                    ]),

                Section::make(__('Password'))
                    ->columns(['default' => 1, 'md' => 2])
                    ->description(fn (string $operation) => $operation === 'edit' ? __('Leave empty to keep the current password. Changing it logs the user out of all devices.') : null)
                    ->schema([
                        TextInput::make('password')
                            ->label(__('Password'))
                            ->password()
                            ->revealable()
                            ->rule(Password::default())
                            ->required(fn (string $operation) => $operation === 'create')
                            ->dehydrated(fn (?string $state) => filled($state))
                            ->autocomplete('new-password')
                            ->same('password_confirmation'),

                        TextInput::make('password_confirmation')
                            ->label(__('Confirm password'))
                            ->password()
                            ->revealable()
                            ->required(fn (Get $get) => filled($get('password')))
                            ->dehydrated(false),
                    ]),

                Section::make(__('Role & access'))
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        Select::make('roles')
                            ->label(__('Role'))
                            ->helperText(__('The role is a template. Extra permissions can be set per user on the Permissions page.'))
                            ->multiple()
                            ->options(fn () => Role::query()->where('name', '<>', RoleEnum::Technician->value)->orderBy('id')->get()->mapWithKeys(fn (Role $r) => [$r->name => RoleService::label($r)]))
                            ->required()
                            ->native(false),

                        Toggle::make('is_active')
                            ->label(__('Active'))
                            ->helperText(__('Deactivated users cannot log in. Their history is kept.'))
                            ->default(true)
                            ->disabled(fn (?User $record) => $record !== null && $record->is(Auth::user())),
                    ]),

                Section::make(__('Stations'))
                    ->columns(['default' => 1, 'md' => 2])
                    ->description(__('Default "where" for every permission. Can be narrowed or widened per permission.'))
                    ->schema([
                        Toggle::make('all_branches')
                            ->label(__('All stations'))
                            ->live()
                            ->columnSpanFull(),

                        Select::make('branches')
                            ->label(__('Stations'))
                            ->multiple()
                            ->options(fn () => self::branchOptions())
                            ->hidden(fn (Get $get) => (bool) $get('all_branches'))
                            ->required(fn (Get $get) => ! $get('all_branches'))
                            ->live()
                            ->native(false),

                        Select::make('primary_branch_id')
                            ->label(__('Main station'))
                            ->helperText(__('The station the operations app opens in.'))
                            ->options(function (Get $get) {
                                $options = self::branchOptions();

                                if ($get('all_branches')) {
                                    return $options;
                                }

                                return array_intersect_key($options, array_flip(array_map('intval', $get('branches') ?? [])));
                            })
                            ->native(false),
                    ]),

                Section::make(__('Security'))
                    ->schema([
                        TextInput::make('session_timeout_minutes')
                            ->label(__('Log out after inactivity (minutes)'))
                            ->helperText(fn () => __('Empty = system default (:minutes min). Use a short value for shared devices.', [
                                'minutes' => app(SettingsRepository::class)->int('security.session_timeout_minutes'),
                            ]))
                            ->numeric()
                            ->minValue(5)
                            ->maxValue(10080),
                    ]),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private static function branchOptions(): array
    {
        return Branch::query()->active()->orderBy('code')->get()
            ->mapWithKeys(fn (Branch $b) => [$b->id => $b->label()])
            ->all();
    }
}
