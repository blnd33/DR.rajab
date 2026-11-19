<?php

namespace App\Filament\Admin\Resources\Categories;

use App\Enums\Specialty;
use App\Filament\Admin\Forms\TranslatableInput;
use App\Filament\Admin\Resources\Categories\Pages\ManageCategories;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Models\Category;
use App\Support\Database\Pg;
use App\Support\Locale\Display;
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

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolder;

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return __('Catalog');
    }

    public static function getModelLabel(): string
    {
        return __('Category');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Categories');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('code')
                ->label(__('Code'))
                ->helperText(__('Short code used in Excel files (e.g. KNEE).'))
                ->required()
                ->maxLength(32)
                ->unique(ignoreRecord: true)
                ->extraInputAttributes(['dir' => 'ltr', 'class' => 'ot-code', 'style' => 'text-transform: uppercase']),
            Select::make('parent_id')
                ->label(__('Parent category'))
                ->options(fn (?Category $record) => collect(Category::options())->except([$record->id ?? 0]))
                ->searchable()
                ->native(false),
            TranslatableInput::make('name', __('Name'))->columnSpanFull(),
            Select::make('specialty')
                ->label(__('Specialty'))
                ->options(Specialty::options())
                ->native(false)
                ->helperText(__('Products in this category (and its sub-categories without their own) count under this specialty on the Main page.')),
            TextInput::make('sort')->label(__('Order'))->numeric()->integer()->default(0),
            Toggle::make('is_active')->label(__('Active'))->default(true)->inline(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('parent.parent')->withCount('products'))
            ->defaultSort('sort')
            ->columns([
                CodeColumn::make('code')->label(__('Code'))->sortable()->searchable(),
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->state(fn (Category $record) => $record->path())
                    ->searchable(query: fn ($query, string $search) => $query->whereRaw(Pg::jsonContainsSql('name'), ["%{$search}%"])),
                TextColumn::make('specialty')->label(__('Specialty'))->badge()->color('info')->placeholder('—'),
                TextColumn::make('products_count')->label(__('Products'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state)),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label(__('Active'))->default(true),
            ])
            ->recordActions([EditAction::make()])
            ->headerActions([CreateAction::make()])
            ->emptyStateHeading(__('No categories yet'));
    }

    public static function getPages(): array
    {
        return ['index' => ManageCategories::route('/')];
    }
}
