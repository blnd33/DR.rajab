<?php

namespace App\Filament\Admin\Resources\StockCounts;

use App\Enums\StockCountStatus;
use App\Filament\Admin\Infolists\LocalDateTimeEntry;
use App\Filament\Admin\Resources\StockCounts\Pages\CreateStockCount;
use App\Filament\Admin\Resources\StockCounts\Pages\ListStockCounts;
use App\Filament\Admin\Resources\StockCounts\Pages\ViewStockCount;
use App\Filament\Admin\Resources\StockCounts\RelationManagers\CountLinesRelationManager;
use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Filament\Admin\Tables\Columns\LocalDateTimeColumn;
use App\Models\StockCount;
use App\Support\Locale\Display;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Stock counts (Module 16).
 */
class StockCountResource extends Resource
{
    protected static ?string $model = StockCount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getNavigationGroup(): ?string
    {
        return __('Operations');
    }

    public static function getModelLabel(): string
    {
        return __('Stock count');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Stock counts');
    }

    public static function getEloquentQuery(): Builder
    {
        return ServiceAction::scoped(parent::getEloquentQuery(), 'stock_counts.view_any', ['branch_id']);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->whereIn('status', [StockCountStatus::Submitted, StockCountStatus::Reviewed])->count();

        return $count > 0 ? Display::number($count) : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['branch', 'category', 'location', 'starter'])->withCount('lines'))
            ->defaultSort('started_at', 'desc')
            ->columns([
                CodeColumn::make('number')->label(__('Number'))->searchable()->sortable(),
                TextColumn::make('branch.code')->label(__('Branch'))->badge()->color('gray'),
                TextColumn::make('scope')->label(__('What'))->state(fn (StockCount $r) => $r->scopeLabel()),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('lines_count')->label(__('Lines'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state)),
                TextColumn::make('starter.name')->label(__('Started by'))->toggleable(),
                LocalDateTimeColumn::make('started_at')->label(__('Started'))->sortable(),
            ])
            ->filters([SelectFilter::make('status')->label(__('Status'))->options(StockCountStatus::options())->multiple()])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading(__('No stock counts yet'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(['default' => 2, 'md' => 4])->schema([
                TextEntry::make('number')->label(__('Number'))->fontFamily(FontFamily::Mono)->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('status')->label(__('Status'))->badge(),
                TextEntry::make('branch.code')->label(__('Branch')),
                TextEntry::make('scope')->label(__('What'))->state(fn (StockCount $r) => $r->scopeLabel()),
                LocalDateTimeEntry::make('snapshot_at')->label(__('Snapshot taken')),
                TextEntry::make('starter.name')->label(__('Started by')),
                LocalDateTimeEntry::make('submitted_at')->label(__('Submitted'))->placeholder('—'),
                TextEntry::make('pending')->label(__('Differences to decide'))->state(fn (StockCount $r) => Display::number($r->pendingDifferences())),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [CountLinesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockCounts::route('/'),
            'create' => CreateStockCount::route('/create'),
            'view' => ViewStockCount::route('/{record}'),
        ];
    }
}
