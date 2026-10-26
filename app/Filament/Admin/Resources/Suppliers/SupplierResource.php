<?php

namespace App\Filament\Admin\Resources\Suppliers;

use App\Filament\Admin\Resources\Suppliers\Pages\ManageSuppliers;
use App\Filament\Admin\Resources\Suppliers\Pages\ViewSupplier;
use App\Filament\Admin\Resources\Suppliers\RelationManagers\StatementRelationManager;
use App\Filament\Admin\Resources\Suppliers\RelationManagers\SupplierPurchasesRelationManager;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Models\Currency;
use App\Models\Supplier;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
 * Supplier master data (Module 7) with their purchases, payments and statement.
 */
class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?int $navigationSort = 50;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('Catalog');
    }

    public static function getModelLabel(): string
    {
        return __('Supplier');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Suppliers');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('code')
                ->label(__('Code'))
                ->required()
                ->maxLength(32)
                ->unique(ignoreRecord: true)
                ->extraInputAttributes(['dir' => 'ltr', 'class' => 'ot-code', 'style' => 'text-transform: uppercase']),
            TextInput::make('name')->label(__('Name'))->required()->maxLength(150),
            TextInput::make('country')->label(__('Country'))->maxLength(64),
            Select::make('default_currency')->label(__('Default currency'))->options(fn () => Currency::options())->default('USD')->required()->native(false),
            TextInput::make('contact_person')->label(__('Contact person'))->maxLength(120),
            TextInput::make('phone')->label(__('Phone'))->tel()->maxLength(32)->extraInputAttributes(['dir' => 'ltr']),
            TextInput::make('email')->label(__('Email'))->email()->maxLength(191)->extraInputAttributes(['dir' => 'ltr']),
            TextInput::make('payment_terms_days')->label(__('Payment terms (days)'))->numeric()->integer()->minValue(0)->maxValue(365),
            Textarea::make('address')->label(__('Address'))->rows(2)->columnSpanFull(),
            Textarea::make('notes')->label(__('Notes'))->rows(3)->columnSpanFull(),
            Toggle::make('is_active')->label(__('Active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                CodeColumn::make('code')->label(__('Code'))->sortable()->searchable(),
                TextColumn::make('name')->label(__('Name'))->sortable()->searchable(),
                TextColumn::make('country')->label(__('Country'))->toggleable(),
                TextColumn::make('contact_person')->label(__('Contact person'))->toggleable(),
                CodeColumn::make('phone')->label(__('Phone'))->toggleable(),
                TextColumn::make('default_currency')->label(__('Currency'))->badge()->color('gray'),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->filters([TernaryFilter::make('is_active')->label(__('Active'))->default(true)])
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->headerActions([CreateAction::make()])
            ->emptyStateHeading(__('No suppliers yet'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSuppliers::route('/'),
            'view' => ViewSupplier::route('/{record}'),
        ];
    }

    public static function getRelations(): array
    {
        return [StatementRelationManager::class, SupplierPurchasesRelationManager::class];
    }
}
