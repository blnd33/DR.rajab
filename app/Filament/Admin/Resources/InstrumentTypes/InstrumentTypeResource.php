<?php

namespace App\Filament\Admin\Resources\InstrumentTypes;

use App\Filament\Admin\Forms\TranslatableInput;
use App\Filament\Admin\Resources\InstrumentTypes\Pages\ManageInstrumentTypes;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Models\InstrumentType;
use App\Support\Database\Pg;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Kinds of reusable instruments (Module 18 / 29).
 */
class InstrumentTypeResource extends Resource
{
    protected static ?string $model = InstrumentType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?int $navigationSort = 92;

    public static function getNavigationGroup(): ?string
    {
        return __('Clinical');
    }

    public static function getModelLabel(): string
    {
        return __('Instrument type');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Instrument types');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('code')->label(__('Code'))->required()->maxLength(32)->unique(ignoreRecord: true)
                ->extraInputAttributes(['dir' => 'ltr', 'class' => 'ot-code', 'style' => 'text-transform: uppercase']),
            Toggle::make('is_active')->label(__('Active'))->default(true),
            TranslatableInput::make('name', __('Name'))->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->columns([
                CodeColumn::make('code')->label(__('Code'))->sortable()->searchable(),
                TextColumn::make('name')->label(__('Name'))
                    ->searchable(query: fn ($query, string $search) => $query->whereRaw(Pg::jsonContainsSql('name'), ["%{$search}%"])),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->filters([TernaryFilter::make('is_active')->label(__('Active'))->default(true)])
            ->recordActions([EditAction::make()])
            ->headerActions([CreateAction::make()])
            ->emptyStateHeading(__('No instrument types yet'));
    }

    public static function getPages(): array
    {
        return ['index' => ManageInstrumentTypes::route('/')];
    }
}
