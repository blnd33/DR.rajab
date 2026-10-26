<?php

namespace App\Filament\Admin\Resources\SupplierReturns;

use App\Enums\SupplierReturnStatus;
use App\Filament\Admin\Infolists\LocalDateTimeEntry;
use App\Filament\Admin\Resources\SupplierReturns\Pages\CreateSupplierReturn;
use App\Filament\Admin\Resources\SupplierReturns\Pages\ListSupplierReturns;
use App\Filament\Admin\Resources\SupplierReturns\Pages\ViewSupplierReturn;
use App\Filament\Admin\Resources\SupplierReturns\RelationManagers\SupplierReturnItemsRelationManager;
use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Filament\Admin\Tables\Columns\LocalDateTimeColumn;
use App\Models\SupplierReturn;
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
 * Items going back to suppliers (Module 14). The credit note arrives with Phase 4.
 */
class SupplierReturnResource extends Resource
{
    protected static ?string $model = SupplierReturn::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnRight;

    protected static ?int $navigationSort = 55;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getNavigationGroup(): ?string
    {
        return __('Operations');
    }

    public static function getModelLabel(): string
    {
        return __('Supplier return');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Supplier returns');
    }

    public static function getEloquentQuery(): Builder
    {
        return ServiceAction::scoped(parent::getEloquentQuery(), 'returns.view_any', ['branch_id']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['branch', 'supplier'])->withSum('items', 'quantity'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                CodeColumn::make('number')->label(__('Number'))->searchable()->sortable(),
                TextColumn::make('supplier.name')->label(__('Supplier')),
                TextColumn::make('branch.code')->label(__('Branch'))->badge()->color('gray'),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('items_sum_quantity')->label(__('Items'))->alignEnd()->formatStateUsing(fn ($state) => Display::number((int) $state)),
                TextColumn::make('total_credit')->label(__('Credit expected'))->alignEnd()
                    ->state(fn (SupplierReturn $r) => $r->currency ? $r->currency.' '.Display::number($r->total_credit, 2) : '—'),
                LocalDateTimeColumn::make('sent_at')->label(__('Sent'))->placeholder('—'),
            ])
            ->filters([SelectFilter::make('status')->label(__('Status'))->options(SupplierReturnStatus::options())])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading(__('No supplier returns'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(['default' => 2, 'md' => 4])->schema([
                TextEntry::make('number')->label(__('Number'))->fontFamily(FontFamily::Mono)->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('status')->label(__('Status'))->badge(),
                TextEntry::make('supplier.name')->label(__('Supplier')),
                TextEntry::make('branch.code')->label(__('Branch')),
                TextEntry::make('total_credit')->label(__('Credit expected'))->state(fn (SupplierReturn $r) => $r->currency ? $r->currency.' '.Display::number($r->total_credit, 2) : '—'),
                LocalDateTimeEntry::make('sent_at')->label(__('Sent'))->placeholder('—'),
                TextEntry::make('notes')->label(__('Notes'))->placeholder('—')->columnSpan(2),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [SupplierReturnItemsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupplierReturns::route('/'),
            'create' => CreateSupplierReturn::route('/create'),
            'view' => ViewSupplierReturn::route('/{record}'),
        ];
    }
}
