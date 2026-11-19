<?php

namespace App\Filament\Admin\Resources\SurgeryTypes;

use App\Filament\Admin\Forms\TranslatableInput;
use App\Filament\Admin\Resources\SurgeryTypes\Pages\ManageSurgeryTypes;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Models\KitType;
use App\Models\SurgeryType;
use App\Support\Database\Pg;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
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
 * Configurable, translatable surgery types (Module 10 / 29).
 */
class SurgeryTypeResource extends Resource
{
    protected static ?string $model = SurgeryType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?int $navigationSort = 80;

    public static function getNavigationGroup(): ?string
    {
        return __('Clinical');
    }

    public static function getModelLabel(): string
    {
        return __('Surgery type');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Surgery types');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('code')->label(__('Code'))->required()->maxLength(32)->unique(ignoreRecord: true)
                ->extraInputAttributes(['dir' => 'ltr', 'class' => 'ot-code', 'style' => 'text-transform: uppercase']),
            Select::make('default_kit_type_id')->label(__('Usual instrument set type'))->options(fn () => KitType::options())->native(false),
            TranslatableInput::make('name', __('Name'))->columnSpanFull(),
            TextInput::make('sort')->label(__('Order'))->numeric()->integer()->default(0),
            Toggle::make('is_active')->label(__('Active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                CodeColumn::make('code')->label(__('Code'))->sortable()->searchable(),
                TextColumn::make('name')->label(__('Name'))
                    ->searchable(query: fn ($query, string $search) => $query->whereRaw(Pg::jsonContainsSql('name'), ["%{$search}%"])),
                TextColumn::make('defaultKitType.code')->label(__('Usual instrument set type'))->placeholder('—'),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->filters([TernaryFilter::make('is_active')->label(__('Active'))->default(true)])
            ->recordActions([EditAction::make()])
            ->headerActions([CreateAction::make()])
            ->emptyStateHeading(__('No surgery types yet'));
    }

    public static function getPages(): array
    {
        return ['index' => ManageSurgeryTypes::route('/')];
    }
}
