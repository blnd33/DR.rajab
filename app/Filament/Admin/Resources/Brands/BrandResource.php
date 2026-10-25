<?php

namespace App\Filament\Admin\Resources\Brands;

use App\Filament\Admin\Resources\Brands\Pages\ManageBrands;
use App\Models\Brand;
use App\Support\Locale\Display;
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

class BrandResource extends Resource
{
    protected static ?string $model = Brand::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return __('Catalog');
    }

    public static function getModelLabel(): string
    {
        return __('Brand');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Brands');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label(__('Name'))->required()->maxLength(120)->unique(ignoreRecord: true),
            TextInput::make('country')->label(__('Country'))->maxLength(64),
            Toggle::make('is_active')->label(__('Active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('products'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('Name'))->sortable()->searchable(),
                TextColumn::make('country')->label(__('Country'))->toggleable(),
                TextColumn::make('products_count')->label(__('Products'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state)),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->filters([TernaryFilter::make('is_active')->label(__('Active'))->default(true)])
            ->recordActions([EditAction::make()])
            ->headerActions([CreateAction::make()])
            ->emptyStateHeading(__('No brands yet'));
    }

    public static function getPages(): array
    {
        return ['index' => ManageBrands::route('/')];
    }
}
