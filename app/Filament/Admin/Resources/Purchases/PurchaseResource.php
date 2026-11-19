<?php

namespace App\Filament\Admin\Resources\Purchases;

use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use App\Enums\ReceivingStatus;
use App\Filament\Admin\Infolists\LocalDateTimeEntry;
use App\Filament\Admin\Resources\Purchases\Pages\CreatePurchase;
use App\Filament\Admin\Resources\Purchases\Pages\ListPurchases;
use App\Filament\Admin\Resources\Purchases\Pages\ViewPurchase;
use App\Filament\Admin\Resources\Purchases\RelationManagers\PurchaseItemsRelationManager;
use App\Filament\Admin\Resources\Purchases\RelationManagers\PurchaseLandedCostsRelationManager;
use App\Filament\Admin\Resources\Purchases\RelationManagers\PurchasePaymentsRelationManager;
use App\Filament\Admin\Resources\Purchases\RelationManagers\PurchaseReceiptsRelationManager;
use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\Purchasing\PurchaseService;
use App\Support\Locale\Display;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Supplier invoices (Module 7, P4-7): draft → confirm → receive → pay, landed costs on top.
 */
class PurchaseResource extends Resource
{
    protected static ?string $model = Purchase::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getNavigationGroup(): ?string
    {
        return __('Finance');
    }

    public static function getModelLabel(): string
    {
        return __('Purchase');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Purchases');
    }

    public static function getEloquentQuery(): Builder
    {
        return ServiceAction::scoped(parent::getEloquentQuery(), 'purchases.view_any', ['branch_id']);
    }

    public static function isOverdue(Purchase $purchase): bool
    {
        return $purchase->status === PurchaseStatus::Confirmed
            && $purchase->payment_status !== PaymentStatus::Paid
            && $purchase->due_date !== null
            && $purchase->due_date->isBefore(today());
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', PurchaseStatus::Confirmed)
            ->where('payment_status', '<>', PaymentStatus::Paid)->whereDate('due_date', '<', today())->count();

        return $count > 0 ? Display::number($count) : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('Overdue supplier invoices');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['supplier', 'branch']))
            ->defaultSort('invoice_date', 'desc')
            ->columns([
                CodeColumn::make('number')->label(__('Number'))->searchable()->sortable(),
                TextColumn::make('supplier.name')->label(__('Supplier'))->searchable()
                    ->description(fn (Purchase $r) => $r->supplier_invoice_number)->extraAttributes(['dir' => 'auto']),
                TextColumn::make('branch.code')->label(__('Station'))->badge()->color('gray'),
                TextColumn::make('invoice_date')->label(__('Invoice date'))->sortable()->formatStateUsing(fn ($state) => Display::date($state)),
                TextColumn::make('due_date')->label(__('Due'))->sortable()->placeholder('—')
                    ->formatStateUsing(fn ($state) => Display::date($state))
                    ->color(fn (Purchase $r) => static::isOverdue($r) ? 'danger' : null)
                    ->description(fn (Purchase $r) => static::isOverdue($r) ? __('Overdue') : null),
                TextColumn::make('total')->label(__('Total'))->alignEnd()->weight('semibold')->formatStateUsing(fn ($state, Purchase $r) => Display::money($state, $r->currency)),
                TextColumn::make('open')->label(__('Open'))->alignEnd()
                    ->state(fn (Purchase $r) => $r->status === PurchaseStatus::Confirmed ? Display::money(app(PurchaseService::class)->open($r), $r->currency) : '—'),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('receiving_status')->label(__('Received'))->badge()->toggleable(),
                TextColumn::make('payment_status')->label(__('Paid'))->badge()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(PurchaseStatus::options()),
                SelectFilter::make('receiving_status')->label(__('Received'))->options(ReceivingStatus::options()),
                SelectFilter::make('payment_status')->label(__('Paid'))->options(PaymentStatus::options()),
                SelectFilter::make('supplier_id')->label(__('Supplier'))->options(fn () => Supplier::options())->searchable(),
                Filter::make('overdue')->label(__('Overdue'))->toggle()
                    ->query(fn (Builder $query) => $query->where('status', PurchaseStatus::Confirmed)->where('payment_status', '<>', PaymentStatus::Paid)->whereDate('due_date', '<', today())),
            ])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading(__('No purchases yet'))
            ->emptyStateDescription(__('Enter a supplier invoice to receive its items with the right cost.'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(['default' => 2, 'md' => 4])->schema([
                TextEntry::make('number')->label(__('Number'))->fontFamily(FontFamily::Mono)->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('supplier.name')->label(__('Supplier')),
                TextEntry::make('supplier_invoice_number')->label(__('Supplier invoice no.'))->fontFamily(FontFamily::Mono)->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('branch.name')->label(__('Station')),
                TextEntry::make('invoice_date')->label(__('Invoice date'))->formatStateUsing(fn ($state) => Display::date($state)),
                TextEntry::make('due_date')->label(__('Due'))->placeholder('—')->formatStateUsing(fn ($state) => Display::date($state))
                    ->color(fn (Purchase $r) => static::isOverdue($r) ? 'danger' : null),
                TextEntry::make('status')->label(__('Status'))->badge(),
                TextEntry::make('receiving_status')->label(__('Received'))->badge(),
                TextEntry::make('subtotal')->label(__('Subtotal'))->formatStateUsing(fn ($state, Purchase $r) => Display::money($state, $r->currency)),
                TextEntry::make('discount')->label(__('Discount'))->formatStateUsing(fn ($state, Purchase $r) => Display::money($state, $r->currency)),
                TextEntry::make('total')->label(__('Total'))->weight('bold')->formatStateUsing(fn ($state, Purchase $r) => Display::money($state, $r->currency)),
                TextEntry::make('open')->label(__('Still to pay'))->state(fn (Purchase $r) => Display::money(app(PurchaseService::class)->open($r), $r->currency))
                    ->badge()->color(fn (Purchase $r) => $r->payment_status === PaymentStatus::Paid ? 'success' : 'warning'),
                LocalDateTimeEntry::make('confirmed_at')->label(__('Confirmed'))->placeholder('—'),
                TextEntry::make('void_reason')->label(__('Cancelled because'))->placeholder('—')->color('danger')->visible(fn (Purchase $r) => $r->status === PurchaseStatus::Cancelled),
                TextEntry::make('notes')->label(__('Notes'))->placeholder('—')->columnSpan(2),
                ViewEntry::make('files')->label(__('Invoice files'))->view('filament.attachments.gallery')->columnSpanFull()
                    ->visible(fn (Purchase $r) => $r->attachments()->exists()),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            PurchaseItemsRelationManager::class,
            PurchaseReceiptsRelationManager::class,
            PurchaseLandedCostsRelationManager::class,
            PurchasePaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchases::route('/'),
            'create' => CreatePurchase::route('/create'),
            'view' => ViewPurchase::route('/{record}'),
        ];
    }
}
