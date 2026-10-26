<?php

namespace App\Filament\Admin\Resources\Customers;

use App\Enums\CustomerType;
use App\Filament\Admin\Forms\TranslatableInput;
use App\Filament\Admin\Resources\Customers\Pages\ManageCustomers;
use App\Filament\Admin\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Admin\Resources\Customers\RelationManagers\CustomerKitCheckoutsRelationManager;
use App\Filament\Admin\Resources\Customers\RelationManagers\CustomerReturnsRelationManager;
use App\Filament\Admin\Resources\Customers\RelationManagers\CustomerSurgeriesRelationManager;
use App\Filament\Admin\Resources\Suppliers\RelationManagers\StatementRelationManager;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Models\Currency;
use App\Models\Customer;
use App\Support\Database\Pg;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Hospitals and other customers (Module 11). Credit sales stay off until the open question
 * is answered (Settings → Optional features).
 */
class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 60;

    protected static ?string $recordTitleAttribute = 'code';

    public static function getNavigationGroup(): ?string
    {
        return __('Catalog');
    }

    public static function getModelLabel(): string
    {
        return __('Hospital / customer');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Hospitals & customers');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('code')->label(__('Code'))->required()->maxLength(32)->unique(ignoreRecord: true)
                ->extraInputAttributes(['dir' => 'ltr', 'class' => 'ot-code', 'style' => 'text-transform: uppercase']),
            Select::make('type')->label(__('Type'))->options(CustomerType::options())->default(CustomerType::Hospital->value)->required()->native(false),
            TranslatableInput::make('name', __('Name'))->columnSpanFull(),
            TranslatableInput::make('city', __('City'), required: false)->columnSpanFull(),
            TextInput::make('contact_person')->label(__('Contact person'))->maxLength(120),
            TextInput::make('phone')->label(__('Phone'))->tel()->maxLength(32)->extraInputAttributes(['dir' => 'ltr']),
            TextInput::make('email')->label(__('Email'))->email()->maxLength(191)->extraInputAttributes(['dir' => 'ltr']),
            TextInput::make('tax_number')->label(__('Tax number'))->maxLength(64)->extraInputAttributes(['dir' => 'ltr']),
            Select::make('default_currency')->label(__('Default currency'))->options(fn () => Currency::options())->default('USD')->required()->native(false),
            Textarea::make('address')->label(__('Address'))->rows(2)->columnSpanFull(),
            Textarea::make('notes')->label(__('Notes'))->rows(2)->columnSpanFull(),
            Toggle::make('is_active')->label(__('Active'))->default(true),
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
                TextColumn::make('type')->label(__('Type'))->badge(),
                TextColumn::make('city')->label(__('City'))->toggleable(),
                TextColumn::make('contact_person')->label(__('Contact person'))->toggleable(),
                CodeColumn::make('phone')->label(__('Phone'))->toggleable(),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->filters([
                SelectFilter::make('type')->label(__('Type'))->options(CustomerType::options()),
                TernaryFilter::make('is_active')->label(__('Active'))->default(true),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->headerActions([CreateAction::make()])
            ->emptyStateHeading(__('No hospitals or customers yet'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCustomers::route('/'),
            'view' => ViewCustomer::route('/{record}'),
        ];
    }

    public static function getRelations(): array
    {
        return [
            CustomerKitCheckoutsRelationManager::class,
            CustomerSurgeriesRelationManager::class,
            CustomerReturnsRelationManager::class,
            StatementRelationManager::class,
        ];
    }
}
