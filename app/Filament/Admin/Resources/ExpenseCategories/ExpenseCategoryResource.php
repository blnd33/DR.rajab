<?php

namespace App\Filament\Admin\Resources\ExpenseCategories;

use App\Filament\Admin\Forms\TranslatableInput;
use App\Filament\Admin\Resources\ExpenseCategories\Pages\ManageExpenseCategories;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Models\ExpenseCategory;
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
 * Expense categories (Module 21): rent, salaries, fuel… Never deleted, only switched off.
 */
class ExpenseCategoryResource extends Resource
{
    protected static ?string $model = ExpenseCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?int $navigationSort = 65;

    public static function getNavigationGroup(): ?string
    {
        return __('Finance');
    }

    public static function getModelLabel(): string
    {
        return __('Expense category');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Expense categories');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('code')->label(__('Code'))->required()->maxLength(32)->unique(ignoreRecord: true)
                ->extraInputAttributes(['dir' => 'ltr', 'class' => 'ot-code', 'style' => 'text-transform: uppercase']),
            Select::make('parent_id')->label(__('Under'))->options(fn () => ExpenseCategory::options())->native(false),
            TranslatableInput::make('name', __('Name'))->columnSpanFull(),
            TextInput::make('sort')->label(__('Order'))->numeric()->integer()->default(0),
            Toggle::make('is_active')->label(__('Active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('parent'))
            ->defaultSort('sort')
            ->columns([
                CodeColumn::make('code')->label(__('Code'))->searchable(),
                TextColumn::make('name')->label(__('Name'))->description(fn (ExpenseCategory $r) => $r->parent?->name),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->filters([TernaryFilter::make('is_active')->label(__('Active'))->default(true)])
            ->recordActions([EditAction::make()])
            ->headerActions([CreateAction::make()])
            ->emptyStateHeading(__('No expense categories'));
    }

    public static function getPages(): array
    {
        return ['index' => ManageExpenseCategories::route('/')];
    }
}
