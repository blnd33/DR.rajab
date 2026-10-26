<?php

namespace App\Filament\Admin\Resources\SalesInvoices;

use App\Enums\PaymentTerms;
use App\Enums\SalesInvoiceStatus;
use App\Filament\Admin\Infolists\LocalDateTimeEntry;
use App\Filament\Admin\Resources\SalesInvoices\Pages\CreateSalesInvoice;
use App\Filament\Admin\Resources\SalesInvoices\Pages\ListSalesInvoices;
use App\Filament\Admin\Resources\SalesInvoices\Pages\ViewSalesInvoice;
use App\Filament\Admin\Resources\SalesInvoices\RelationManagers\SalesInvoiceItemsRelationManager;
use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Filament\Admin\Tables\Columns\LocalDateTimeColumn;
use App\Models\SalesInvoice;
use App\Support\Locale\Display;
use BackedEnum;
use Brick\Math\BigDecimal;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
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
 * Sales of stock to hospitals or walk-in buyers (Module 9, P4-12): cash now, or on credit
 * when the credit-sales switch is on.
 */
class SalesInvoiceResource extends Resource
{
    protected static ?string $model = SalesInvoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyDollar;

    protected static ?int $navigationSort = 50;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getNavigationGroup(): ?string
    {
        return __('Finance');
    }

    public static function getModelLabel(): string
    {
        return __('Sale');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Sales');
    }

    public static function getEloquentQuery(): Builder
    {
        return ServiceAction::scoped(parent::getEloquentQuery(), 'sales.view_any', ['branch_id']);
    }

    public static function open(SalesInvoice $invoice): string
    {
        return (string) BigDecimal::of($invoice->total)->minus($invoice->paid_amount)->toScale(4);
    }

    public static function isOverdue(SalesInvoice $invoice): bool
    {
        return in_array($invoice->status, [SalesInvoiceStatus::Issued, SalesInvoiceStatus::PartiallyPaid], true)
            && $invoice->due_date !== null && $invoice->due_date->isBefore(today());
    }

    public static function buyer(SalesInvoice $invoice): string
    {
        return $invoice->customer->name ?? ($invoice->walk_in_name ?: __('Walk-in'));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['customer', 'branch'])->withCount('items'))
            ->defaultSort('issued_at', 'desc')
            ->columns([
                CodeColumn::make('number')->label(__('Number'))->searchable()->sortable(),
                TextColumn::make('buyer')->label(__('Buyer'))->state(fn (SalesInvoice $r) => static::buyer($r)),
                TextColumn::make('branch.code')->label(__('Branch'))->badge()->color('gray'),
                LocalDateTimeColumn::make('issued_at')->label(__('Date'))->sortable(),
                TextColumn::make('items_count')->label(__('Items'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state)),
                TextColumn::make('total')->label(__('Total'))->alignEnd()->weight('semibold')->formatStateUsing(fn ($state, SalesInvoice $r) => Display::money($state, $r->currency)),
                TextColumn::make('payment_terms')->label(__('Terms'))->badge()->color('gray')->toggleable(),
                TextColumn::make('due_date')->label(__('Due'))->placeholder('—')->formatStateUsing(fn ($state) => Display::date($state))
                    ->color(fn (SalesInvoice $r) => static::isOverdue($r) ? 'danger' : null)->toggleable(),
                TextColumn::make('status')->label(__('Status'))->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(SalesInvoiceStatus::options()),
                SelectFilter::make('payment_terms')->label(__('Terms'))->options(PaymentTerms::options()),
                Filter::make('overdue')->label(__('Overdue'))->toggle()
                    ->query(fn (Builder $query) => $query->whereIn('status', [SalesInvoiceStatus::Issued, SalesInvoiceStatus::PartiallyPaid])->whereDate('due_date', '<', today())),
            ])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading(__('No sales yet'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(['default' => 2, 'md' => 4])->schema([
                TextEntry::make('number')->label(__('Number'))->fontFamily(FontFamily::Mono)->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('buyer')->label(__('Buyer'))->state(fn (SalesInvoice $r) => static::buyer($r)),
                TextEntry::make('branch.name')->label(__('Branch')),
                TextEntry::make('status')->label(__('Status'))->badge(),
                TextEntry::make('payment_terms')->label(__('Terms'))->badge()->color('gray'),
                TextEntry::make('due_date')->label(__('Due'))->placeholder('—')->formatStateUsing(fn ($state) => Display::date($state)),
                LocalDateTimeEntry::make('issued_at')->label(__('Date')),
                TextEntry::make('creator.name')->label(__('By')),
                TextEntry::make('subtotal')->label(__('Subtotal'))->formatStateUsing(fn ($state, SalesInvoice $r) => Display::money($state, $r->currency)),
                TextEntry::make('discount')->label(__('Discount'))->formatStateUsing(fn ($state, SalesInvoice $r) => Display::money($state, $r->currency)),
                TextEntry::make('total')->label(__('Total'))->weight('bold')->formatStateUsing(fn ($state, SalesInvoice $r) => Display::money($state, $r->currency)),
                TextEntry::make('open')->label(__('Still to collect'))->state(fn (SalesInvoice $r) => Display::money(static::open($r), $r->currency))
                    ->visible(fn (SalesInvoice $r) => $r->payment_terms === PaymentTerms::Credit),
                TextEntry::make('void_reason')->label(__('Voided because'))->color('danger')->visible(fn (SalesInvoice $r) => $r->status === SalesInvoiceStatus::Voided),
                TextEntry::make('notes')->label(__('Notes'))->placeholder('—')->columnSpan(2),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [SalesInvoiceItemsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSalesInvoices::route('/'),
            'create' => CreateSalesInvoice::route('/create'),
            'view' => ViewSalesInvoice::route('/{record}'),
        ];
    }
}
