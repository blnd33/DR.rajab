<?php

namespace App\Filament\Admin\Resources\KitTypes;

use App\Filament\Admin\Forms\TranslatableInput;
use App\Filament\Admin\Resources\KitTypes\Pages\CreateKitType;
use App\Filament\Admin\Resources\KitTypes\Pages\EditKitType;
use App\Filament\Admin\Resources\KitTypes\Pages\ListKitTypes;
use App\Filament\Admin\Resources\KitTypes\RelationManagers\TemplateItemsRelationManager;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Models\KitType;
use App\Support\Database\Pg;
use App\Support\Locale\Display;
use BackedEnum;
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
use Illuminate\Database\Eloquent\Builder;

/**
 * Kit types with their template: expected instruments and implant sizes (Module 18).
 */
class KitTypeResource extends Resource
{
    protected static ?string $model = KitType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?int $navigationSort = 90;

    public static function getNavigationGroup(): ?string
    {
        return __('Clinical');
    }

    public static function getModelLabel(): string
    {
        return __('Kit type');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Kit types');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('code')->label(__('Code'))->required()->maxLength(32)->unique(ignoreRecord: true)
                ->extraInputAttributes(['dir' => 'ltr', 'class' => 'ot-code', 'style' => 'text-transform: uppercase']),
            Toggle::make('is_active')->label(__('Active'))->default(true),
            TranslatableInput::make('name', __('Name'))->columnSpanFull(),
            TranslatableInput::make('description', __('Description'), required: false)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount(['templateItems', 'kits']))
            ->defaultSort('code')
            ->columns([
                CodeColumn::make('code')->label(__('Code'))->sortable()->searchable(),
                TextColumn::make('name')->label(__('Name'))
                    ->searchable(query: fn ($query, string $search) => $query->whereRaw(Pg::jsonContainsSql('name'), ["%{$search}%"])),
                TextColumn::make('template_items_count')->label(__('Template lines'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state)),
                TextColumn::make('kits_count')->label(__('Kits'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state)),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->filters([TernaryFilter::make('is_active')->label(__('Active'))->default(true)])
            ->recordActions([EditAction::make()])
            ->emptyStateHeading(__('No kit types yet'));
    }

    public static function getRelations(): array
    {
        return [TemplateItemsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKitTypes::route('/'),
            'create' => CreateKitType::route('/create'),
            'edit' => EditKitType::route('/{record}/edit'),
        ];
    }
}
