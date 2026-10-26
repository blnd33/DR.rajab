<?php

namespace App\Filament\Admin\Resources\CashBoxes;

use App\Enums\CashBoxType;
use App\Filament\Admin\Resources\CashBoxes\Pages\CreateCashBox;
use App\Filament\Admin\Resources\CashBoxes\Pages\ListCashBoxes;
use App\Filament\Admin\Resources\CashBoxes\Pages\ViewCashBox;
use App\Filament\Admin\Resources\CashBoxes\RelationManagers\BoxClosingsRelationManager;
use App\Filament\Admin\Resources\CashBoxes\RelationManagers\BoxTransactionsRelationManager;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\CashBox;
use App\Support\Locale\Display;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cash boxes and bank accounts (Module 19): balances, their ledger, closings.
 */
class CashBoxResource extends Resource
{
    protected static ?string $model = CashBox::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return __('Finance');
    }

    public static function getModelLabel(): string
    {
        return __('Cash box');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Cash boxes & banks');
    }

    public static function getEloquentQuery(): Builder
    {
        return ServiceAction::scoped(parent::getEloquentQuery(), 'cash_boxes.view_any', ['branch_id']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('branch'))
            ->defaultSort('currency')
            ->columns([
                TextColumn::make('branch.code')->label(__('Branch'))->badge()->color('gray')->placeholder(__('Head office')),
                TextColumn::make('name')->label(__('Name'))->weight('semibold')->description(fn (CashBox $r) => $r->bank_name),
                TextColumn::make('type')->label(__('Type'))->badge(),
                TextColumn::make('current_balance')->label(__('Balance'))->alignEnd()->weight('bold')
                    ->formatStateUsing(fn ($state, CashBox $r) => Display::money($state, $r->currency))
                    ->color(fn ($state) => (float) $state < 0 ? 'danger' : null),
                IconColumn::make('is_active')->label(__('Open'))->boolean(),
            ])
            ->filters([
                SelectFilter::make('currency')->label(__('Currency'))->options(['USD' => 'USD', 'EUR' => 'EUR', 'IQD' => 'IQD']),
                SelectFilter::make('type')->label(__('Type'))->options(CashBoxType::options()),
                SelectFilter::make('branch_id')->label(__('Branch'))->options(fn () => ServiceAction::branchOptions('cash_boxes.view_any')),
                TernaryFilter::make('is_active')->label(__('Open'))->default(true),
            ])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading(__('No cash boxes yet'))
            ->emptyStateDescription(__('Each branch needs a cash box per currency it handles.'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(['default' => 2, 'md' => 4])->schema([
                TextEntry::make('name')->label(__('Name')),
                TextEntry::make('branch.code')->label(__('Branch'))->placeholder(__('Head office')),
                TextEntry::make('type')->label(__('Type'))->badge(),
                TextEntry::make('current_balance')->label(__('Balance'))->weight('bold')->size('lg')
                    ->formatStateUsing(fn ($state, CashBox $r) => Display::money($state, $r->currency)),
                TextEntry::make('bank_name')->label(__('Bank'))->placeholder('—'),
                TextEntry::make('account_number')->label(__('Account number'))->fontFamily(FontFamily::Mono)->placeholder('—'),
                TextEntry::make('iban')->label(__('IBAN'))->fontFamily(FontFamily::Mono)->placeholder('—'),
                TextEntry::make('is_active')->label(__('Open'))->formatStateUsing(fn ($state) => $state ? __('Yes') : __('No')),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [BoxTransactionsRelationManager::class, BoxClosingsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCashBoxes::route('/'),
            'create' => CreateCashBox::route('/create'),
            'view' => ViewCashBox::route('/{record}'),
        ];
    }
}
