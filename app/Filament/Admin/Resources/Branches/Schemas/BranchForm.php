<?php

namespace App\Filament\Admin\Resources\Branches\Schemas;

use App\Enums\Governorate;
use App\Filament\Admin\Forms\TranslatableInput;
use App\Models\Currency;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class BranchForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('Station'))
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextInput::make('code')
                            ->label(__('Code'))
                            ->helperText(__('2–8 capital letters or digits, used in document numbers (e.g. DHK).'))
                            ->required()
                            ->maxLength(8)
                            ->regex('/^[A-Z0-9]{2,8}$/')
                            ->unique(ignoreRecord: true)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (?string $state, Set $set) => $set('code', mb_strtoupper(trim((string) $state))))
                            ->extraInputAttributes(['dir' => 'ltr', 'class' => 'ot-code', 'style' => 'text-transform: uppercase']),

                        Select::make('default_currency')
                            ->label(__('Default cash currency'))
                            ->options(fn () => Currency::options())
                            ->default('USD')
                            ->required()
                            ->native(false),

                        TranslatableInput::make('name', __('Name'))->columnSpanFull(),
                        TranslatableInput::make('city', __('City'), required: false)->columnSpanFull(),

                        Select::make('governorate')
                            ->label(__('Governorate'))
                            ->options(Governorate::options())
                            ->searchable()
                            ->native(false)
                            ->helperText(__('Used on the Home page: Kurdistan (Duhok, Erbil, Sulaymaniyah, Halabja) or the rest of Iraq.')),

                        TextInput::make('phone')
                            ->label(__('Phone'))
                            ->tel()
                            ->maxLength(32)
                            ->extraInputAttributes(['dir' => 'ltr']),

                        Select::make('manager_id')
                            ->label(__('Manager'))
                            ->options(fn () => User::query()->active()->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->native(false),

                        Textarea::make('address')
                            ->label(__('Address'))
                            ->rows(2)
                            ->columnSpanFull(),

                        Toggle::make('is_head_office')
                            ->label(__('Head office'))
                            ->helperText(__('Only one station can be the head office.'))
                            ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('is_head_office', true))
                            ->validationMessages(['unique' => __('Another station is already the head office.')]),
                    ]),
            ]);
    }
}
