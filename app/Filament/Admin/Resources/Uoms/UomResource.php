<?php

namespace App\Filament\Admin\Resources\Uoms;

use App\Filament\Admin\Forms\TranslatableInput;
use App\Filament\Admin\Resources\Uoms\Pages\ManageUoms;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Models\Uom;
use App\Support\Locale\Display;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UomResource extends Resource
{
    protected static ?string $model = Uom::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?int $navigationSort = 40;

    public static function getNavigationGroup(): ?string
    {
        return __('Catalog');
    }

    public static function getModelLabel(): string
    {
        return __('Unit');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Units');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label(__('Code'))
                ->helperText(__('Short code used in Excel files (pc, box, pack).'))
                ->required()
                ->maxLength(16)
                ->unique(ignoreRecord: true)
                ->extraInputAttributes(['dir' => 'ltr', 'class' => 'ot-code']),
            TranslatableInput::make('name', __('Name')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('products'))
            ->defaultSort('code')
            ->columns([
                CodeColumn::make('code')->label(__('Code'))->sortable(),
                TextColumn::make('name')->label(__('Name')),
                TextColumn::make('products_count')->label(__('Products'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state)),
            ])
            ->recordActions([EditAction::make()])
            ->headerActions([CreateAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageUoms::route('/')];
    }
}
