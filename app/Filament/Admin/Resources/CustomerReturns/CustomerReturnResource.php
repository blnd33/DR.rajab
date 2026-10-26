<?php

namespace App\Filament\Admin\Resources\CustomerReturns;

use App\Filament\Admin\Infolists\LocalDateTimeEntry;
use App\Filament\Admin\Resources\CustomerReturns\Pages\CreateCustomerReturn;
use App\Filament\Admin\Resources\CustomerReturns\Pages\ListCustomerReturns;
use App\Filament\Admin\Resources\CustomerReturns\Pages\ViewCustomerReturn;
use App\Filament\Admin\Resources\CustomerReturns\RelationManagers\ReturnItemsRelationManager;
use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Filament\Admin\Tables\Columns\LocalDateTimeColumn;
use App\Models\CustomerReturn;
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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Items back from hospitals / patients / customers (Module 14).
 */
class CustomerReturnResource extends Resource
{
    protected static ?string $model = CustomerReturn::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static ?int $navigationSort = 50;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getNavigationGroup(): ?string
    {
        return __('Operations');
    }

    public static function getModelLabel(): string
    {
        return __('Customer return');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Customer returns');
    }

    public static function getEloquentQuery(): Builder
    {
        return ServiceAction::scoped(parent::getEloquentQuery(), 'returns.view_any', ['branch_id']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['branch', 'customer', 'receiver'])->withSum('items', 'quantity'))
            ->defaultSort('received_at', 'desc')
            ->columns([
                CodeColumn::make('number')->label(__('Number'))->searchable()->sortable(),
                TextColumn::make('branch.code')->label(__('Branch'))->badge()->color('gray'),
                TextColumn::make('customer.name')->label(__('Hospital / customer'))->placeholder('—'),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('items_sum_quantity')->label(__('Items'))->alignEnd()->formatStateUsing(fn ($state) => Display::number((int) $state)),
                TextColumn::make('receiver.name')->label(__('Received by'))->toggleable(),
                LocalDateTimeColumn::make('received_at')->label(__('Received'))->sortable(),
            ])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading(__('No customer returns'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(['default' => 2, 'md' => 4])->schema([
                TextEntry::make('number')->label(__('Number'))->fontFamily(FontFamily::Mono)->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('status')->label(__('Status'))->badge(),
                TextEntry::make('branch.code')->label(__('Branch')),
                TextEntry::make('customer.name')->label(__('Hospital / customer'))->placeholder('—'),
                TextEntry::make('receiver.name')->label(__('Received by')),
                LocalDateTimeEntry::make('received_at')->label(__('Received')),
                TextEntry::make('notes')->label(__('Notes'))->placeholder('—')->columnSpan(2),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [ReturnItemsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomerReturns::route('/'),
            'create' => CreateCustomerReturn::route('/create'),
            'view' => ViewCustomerReturn::route('/{record}'),
        ];
    }
}
