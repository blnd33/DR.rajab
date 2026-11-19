<?php

namespace App\Filament\Admin\Resources\SalesInvoices;

use App\Enums\InvoiceKind;
use App\Enums\PaymentTerms;
use App\Enums\SalesInvoiceStatus;
use App\Filament\Admin\Infolists\LocalDateTimeEntry;
use App\Filament\Admin\Resources\SalesInvoices\Pages\CreateSalesInvoice;
use App\Filament\Admin\Resources\SalesInvoices\Pages\EditSalesInvoice;
use App\Filament\Admin\Resources\SalesInvoices\Pages\ListSalesInvoices;
use App\Filament\Admin\Resources\SalesInvoices\Pages\ViewSalesInvoice;
use App\Filament\Admin\Resources\SalesInvoices\RelationManagers\SalesInvoiceItemsRelationManager;
use App\Filament\Admin\Resources\Surgeries\SurgeryResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Filament\Admin\Tables\Columns\LocalDateTimeColumn;
use App\Models\CashBox;
use App\Models\SalesInvoice;
use App\Services\Money\CashBoxService;
use App\Services\Permissions\BranchScopeResolver;
use App\Services\Sales\InvoiceService;
use App\Support\Locale\Display;
use BackedEnum;
use Brick\Math\BigDecimal;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
 * Invoices for everything we are paid for: sales of stock (Module 9, P4-12) and surgery fees.
 * An invoice stays unpaid (and editable) until someone marks it paid into a box; only then the
 * money counts as received. Credit invoices (behind the credit-sales switch) are settled by
 * customer payments instead.
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
        return __('Invoice');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Invoices');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', SalesInvoiceStatus::Issued)->where('payment_terms', '<>', PaymentTerms::Credit)->count();

        return $count > 0 ? Display::number($count) : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('Unpaid');
    }

    /**
     * Invoices of the branches where the user may see sales or invoices.
     */
    public static function getEloquentQuery(): Builder
    {
        $scope = app(BranchScopeResolver::class);
        $user = ServiceAction::user();
        $sales = $scope->branchIdsFor($user, 'sales.view_any');
        $invoices = $scope->branchIdsFor($user, 'invoices.view_any');
        $query = parent::getEloquentQuery();

        if ($sales === null || $invoices === null) {
            return $query;
        }

        $ids = array_values(array_unique([...$sales, ...$invoices]));

        return $ids === [] ? $query->whereRaw('1 = 0') : $query->whereIn('branch_id', $ids);
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

    /**
     * What the invoice is for, in one line.
     */
    public static function about(SalesInvoice $invoice): ?string
    {
        if ($invoice->kind !== InvoiceKind::Surgery) {
            return null;
        }

        $surgery = $invoice->surgery;

        return trim(implode(' · ', array_filter([
            $surgery?->number,
            $surgery?->type?->name,
            $surgery?->patientName(),
        ]))) ?: null;
    }

    public static function canMarkPaid(SalesInvoice $invoice): bool
    {
        return $invoice->isOpenForMarking() && ServiceAction::user()->canInBranch('invoices.mark_paid', $invoice->branch_id);
    }

    public static function canMarkNotPaid(SalesInvoice $invoice): bool
    {
        return $invoice->status === SalesInvoiceStatus::Paid && $invoice->payment_terms !== PaymentTerms::Credit
            && ServiceAction::user()->canInBranch('invoices.mark_paid', $invoice->branch_id);
    }

    /**
     * "Mark as paid": pick the box (in the invoice's currency) the money went into.
     */
    public static function markPaidAction(?callable $after = null): Action
    {
        return Action::make('markPaid')
            ->label(__('Mark as paid'))
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->visible(fn (SalesInvoice $record) => static::canMarkPaid($record))
            ->modalHeading(fn (SalesInvoice $record) => __('Invoice :number has been paid', ['number' => $record->number]))
            ->modalDescription(fn (SalesInvoice $record) => __(':amount goes into the box you choose and counts as received.', ['amount' => Display::money($record->total, $record->currency)]))
            ->schema(fn (SalesInvoice $record) => [
                Select::make('cash_box_id')->label(__('Money into'))->required()->native(false)
                    ->options(fn () => app(CashBoxService::class)->options(ServiceAction::user(), 'invoices.mark_paid', $record->currency))
                    ->default(fn () => array_key_first(app(CashBoxService::class)->options(ServiceAction::user(), 'invoices.mark_paid', $record->currency, $record->branch_id)))
                    ->helperText(fn () => app(CashBoxService::class)->options(ServiceAction::user(), 'invoices.mark_paid', $record->currency) === []
                        ? __('There is no :currency box you can take money into.', ['currency' => $record->currency])
                        : null),
            ])
            ->action(function (SalesInvoice $record, array $data, Action $action) use ($after) {
                ServiceAction::run($action, fn ($u) => app(InvoiceService::class)->markPaid($record, CashBox::query()->findOrFail($data['cash_box_id']), $u), __('Invoice marked as paid.'));

                if ($after !== null) {
                    $after();
                }
            });
    }

    /**
     * "Not paid": it was marked paid by mistake; the money leaves the box again.
     */
    public static function markNotPaidAction(?callable $after = null): Action
    {
        return Action::make('markNotPaid')
            ->label(__('Mark as not paid'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('warning')
            ->visible(fn (SalesInvoice $record) => static::canMarkNotPaid($record))
            ->modalDescription(fn (SalesInvoice $record) => __(':amount is taken out of the box again and the invoice can be edited.', ['amount' => Display::money($record->total, $record->currency)]))
            ->schema([Textarea::make('reason')->label(__('Reason'))->required()->rows(2)])
            ->action(function (SalesInvoice $record, array $data, Action $action) use ($after) {
                ServiceAction::run($action, fn ($u) => app(InvoiceService::class)->unmarkPaid($record, $data['reason'], $u), __('Invoice marked as not paid.'));

                if ($after !== null) {
                    $after();
                }
            });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['customer', 'branch', 'surgery.type', 'surgery.patient'])->withCount('items'))
            ->defaultSort('issued_at', 'desc')
            ->columns([
                CodeColumn::make('number')->label(__('Number'))->searchable()->sortable(),
                TextColumn::make('kind')->label(__('For'))->badge()->description(fn (SalesInvoice $r) => static::about($r)),
                TextColumn::make('buyer')->label(__('Buyer'))->state(fn (SalesInvoice $r) => static::buyer($r)),
                TextColumn::make('branch.code')->label(__('Station'))->badge()->color('gray')->toggleable(),
                LocalDateTimeColumn::make('issued_at')->label(__('Date'))->sortable(),
                TextColumn::make('items_count')->label(__('Items'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('total')->label(__('Total'))->alignEnd()->weight('semibold')->sortable()->formatStateUsing(fn ($state, SalesInvoice $r) => Display::money($state, $r->currency)),
                TextColumn::make('payment_terms')->label(__('Terms'))->badge()->color('gray')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('due_date')->label(__('Due'))->placeholder('—')->formatStateUsing(fn ($state) => Display::date($state))
                    ->color(fn (SalesInvoice $r) => static::isOverdue($r) ? 'danger' : null)->toggleable(),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (SalesInvoiceStatus $state) => $state === SalesInvoiceStatus::Issued ? __('Not paid') : $state->getLabel()),
            ])
            ->filters([
                SelectFilter::make('kind')->label(__('For'))->options(InvoiceKind::options()),
                SelectFilter::make('status')->label(__('Status'))->options(SalesInvoiceStatus::options()),
                SelectFilter::make('payment_terms')->label(__('Terms'))->options(PaymentTerms::options()),
                SelectFilter::make('branch_id')->label(__('Station'))->options(fn () => ServiceAction::branchOptions('invoices.view_any') + ServiceAction::branchOptions('sales.view_any')),
                Filter::make('overdue')->label(__('Overdue'))->toggle()
                    ->query(fn (Builder $query) => $query->whereIn('status', [SalesInvoiceStatus::Issued, SalesInvoiceStatus::PartiallyPaid])->whereDate('due_date', '<', today())),
            ])
            ->recordActions([
                static::markPaidAction(),
                Action::make('edit')->label(__('Edit'))->icon(Heroicon::OutlinedPencilSquare)->color('gray')
                    ->visible(fn (SalesInvoice $r) => $r->isOpenForMarking() && ServiceAction::user()->canInBranch('invoices.update', $r->branch_id))
                    ->url(fn (SalesInvoice $r) => static::getUrl('edit', ['record' => $r])),
                ViewAction::make(),
            ])
            ->emptyStateHeading(__('No invoices yet'))
            ->emptyStateDescription(__('Every sale and every surgery with a fee gets an invoice here.'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(['default' => 2, 'md' => 4])->schema([
                TextEntry::make('number')->label(__('Number'))->fontFamily(FontFamily::Mono)->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('kind')->label(__('For'))->badge(),
                TextEntry::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (SalesInvoiceStatus $state) => $state === SalesInvoiceStatus::Issued ? __('Not paid') : $state->getLabel()),
                TextEntry::make('buyer')->label(__('Buyer'))->state(fn (SalesInvoice $r) => static::buyer($r)),
                TextEntry::make('surgery.number')->label(__('Surgery'))->fontFamily(FontFamily::Mono)
                    ->state(fn (SalesInvoice $r) => static::about($r))
                    ->url(fn (SalesInvoice $r) => $r->surgery_id !== null ? SurgeryResource::getUrl('view', ['record' => $r->surgery_id]) : null)
                    ->visible(fn (SalesInvoice $r) => $r->kind === InvoiceKind::Surgery),
                TextEntry::make('branch.name')->label(__('Station')),
                TextEntry::make('payment_terms')->label(__('Terms'))->badge()->color('gray'),
                TextEntry::make('pricing')->label(__('Price'))->badge()->color('gray')
                    ->formatStateUsing(fn ($state) => $state === 'split' ? __('One price, split by %') : __('Per item'))
                    ->visible(fn (SalesInvoice $r) => $r->kind === InvoiceKind::Sale),
                TextEntry::make('due_date')->label(__('Due'))->placeholder('—')->formatStateUsing(fn ($state) => Display::date($state)),
                LocalDateTimeEntry::make('issued_at')->label(__('Date')),
                TextEntry::make('creator.name')->label(__('By')),
                TextEntry::make('subtotal')->label(__('Subtotal'))->formatStateUsing(fn ($state, SalesInvoice $r) => Display::money($state, $r->currency))
                    ->visible(fn (SalesInvoice $r) => $r->kind === InvoiceKind::Sale),
                TextEntry::make('discount')->label(__('Discount'))->formatStateUsing(fn ($state, SalesInvoice $r) => Display::money($state, $r->currency))
                    ->visible(fn (SalesInvoice $r) => $r->kind === InvoiceKind::Sale),
                TextEntry::make('total')->label(__('Total'))->weight('bold')->formatStateUsing(fn ($state, SalesInvoice $r) => Display::money($state, $r->currency)),
                TextEntry::make('open')->label(__('Still to collect'))->state(fn (SalesInvoice $r) => Display::money(static::open($r), $r->currency))
                    ->visible(fn (SalesInvoice $r) => $r->payment_terms === PaymentTerms::Credit || $r->status === SalesInvoiceStatus::Issued),
                LocalDateTimeEntry::make('paid_at')->label(__('Paid'))->visible(fn (SalesInvoice $r) => $r->paid_at !== null),
                TextEntry::make('paidBox.name')->label(__('Money into'))->state(fn (SalesInvoice $r) => $r->paidBox?->label())
                    ->visible(fn (SalesInvoice $r) => $r->paid_cash_box_id !== null),
                TextEntry::make('payer.name')->label(__('Marked paid by'))->visible(fn (SalesInvoice $r) => $r->paid_by !== null),
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
            'edit' => EditSalesInvoice::route('/{record}/edit'),
        ];
    }
}
