<?php

namespace App\Filament\Admin\Resources\StockRequests;

use App\Enums\RequestPriority;
use App\Enums\StockRequestStatus;
use App\Filament\Admin\Resources\StockRequests\Pages\CreateStockRequest;
use App\Filament\Admin\Resources\StockRequests\Pages\ListStockRequests;
use App\Filament\Admin\Resources\StockRequests\Pages\ViewStockRequest;
use App\Filament\Admin\Resources\StockRequests\RelationManagers\LinesRelationManager;
use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Filament\Admin\Tables\Columns\LocalDateTimeColumn;
use App\Models\StockRequest;
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
 * Stock requests between branches (Module 9).
 */
class StockRequestResource extends Resource
{
    protected static ?string $model = StockRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getNavigationGroup(): ?string
    {
        return __('Operations');
    }

    public static function getModelLabel(): string
    {
        return __('Stock request');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Stock requests');
    }

    public static function getEloquentQuery(): Builder
    {
        return ServiceAction::scoped(parent::getEloquentQuery(), 'stock_requests.view_any', ['requesting_branch_id', 'preferred_source_branch_id']);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', StockRequestStatus::Pending)->count();

        return $count > 0 ? Display::number($count) : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['requestingBranch', 'requester'])->withCount('items'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                CodeColumn::make('number')->label(__('Number'))->searchable()->sortable(),
                TextColumn::make('requestingBranch.code')->label(__('For'))->badge()->color('gray'),
                TextColumn::make('priority')->label(__('Priority'))->badge(),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('items_count')->label(__('Products'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state)),
                TextColumn::make('needed_by')->label(__('Needed by'))->formatStateUsing(fn ($state) => Display::date($state))->placeholder('—'),
                TextColumn::make('requester.name')->label(__('By'))->toggleable(),
                LocalDateTimeColumn::make('created_at')->label(__('Requested'))->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(StockRequestStatus::options())->multiple()
                    ->default([StockRequestStatus::Pending->value]),
                SelectFilter::make('priority')->label(__('Priority'))->options(RequestPriority::options()),
            ])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading(__('No stock requests'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(['default' => 2, 'md' => 4])->schema([
                TextEntry::make('number')->label(__('Number'))->fontFamily(FontFamily::Mono)->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('status')->label(__('Status'))->badge(),
                TextEntry::make('priority')->label(__('Priority'))->badge(),
                TextEntry::make('requestingBranch.code')->label(__('For')),
                TextEntry::make('preferredSourceBranch.code')->label(__('Preferred source'))->placeholder(__('Any')),
                TextEntry::make('needed_by')->label(__('Needed by'))->formatStateUsing(fn ($state) => Display::date($state))->placeholder('—'),
                TextEntry::make('requester.name')->label(__('Requested by')),
                TextEntry::make('reviewer.name')->label(__('Decided by'))->placeholder('—'),
                TextEntry::make('notes')->label(__('Notes'))->placeholder('—')->columnSpan(2),
                TextEntry::make('review_note')->label(__('Decision note'))->placeholder('—')->columnSpan(2),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [LinesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockRequests::route('/'),
            'create' => CreateStockRequest::route('/create'),
            'view' => ViewStockRequest::route('/{record}'),
        ];
    }
}
