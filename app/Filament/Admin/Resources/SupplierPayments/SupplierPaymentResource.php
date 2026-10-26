<?php

namespace App\Filament\Admin\Resources\SupplierPayments;

use App\Enums\PaymentMethod;
use App\Enums\SupplierPaymentStatus;
use App\Filament\Admin\Infolists\LocalDateTimeEntry;
use App\Filament\Admin\Resources\SupplierPayments\Pages\ManageSupplierPayments;
use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Models\CashBox;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Services\Money\CashBoxService;
use App\Services\Money\FxService;
use App\Services\Purchasing\PurchaseService;
use App\Services\Purchasing\SupplierPaymentService;
use App\Support\Locale\Display;
use BackedEnum;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Money paid to suppliers (Module 7, P4-8): allocated to open invoices, oldest due first.
 */
class SupplierPaymentResource extends Resource
{
    protected static ?string $model = SupplierPayment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 45;

    public static function getNavigationGroup(): ?string
    {
        return __('Finance');
    }

    public static function getModelLabel(): string
    {
        return __('Supplier payment');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Supplier payments');
    }

    /**
     * The pay form, shared by this list, the purchase page and the supplier page.
     *
     * @return list<Component|Field>
     */
    public static function paySchema(?Purchase $purchase = null, bool $pickSupplier = false): array
    {
        $boxes = fn () => app(CashBoxService::class)->options(ServiceAction::user(), 'cash_boxes.view_any');

        return array_values(array_filter([
            $pickSupplier ? Select::make('supplier_id')->label(__('Supplier'))->options(fn () => Supplier::options())->searchable()->required()->native(false)->live()
                ->helperText(fn (Get $get) => filled($get('supplier_id')) ? __('Owed: :amount', ['amount' => app(SupplierPaymentService::class)->owedText(Supplier::query()->findOrFail($get('supplier_id')))]) : null) : null,
            Select::make('cash_box_id')->label(__('Pay from'))->options($boxes)->required()->native(false)->live()
                ->afterStateUpdated(function ($state, Set $set) use ($purchase) {
                    // Paying one invoice: offer what is still open, in the box's currency at today's rate.
                    $box = filled($state) ? CashBox::query()->find($state) : null;

                    if ($purchase !== null && $box !== null) {
                        $open = app(FxService::class)->converter()->convert(app(PurchaseService::class)->open($purchase), $purchase->currency, $box->currency);
                        $set('amount', (string) BigDecimal::of($open)->toScale(2, RoundingMode::Up));
                    }
                }),
            TextInput::make('amount')->label(fn (Get $get) => __('Amount (:currency)', ['currency' => CashBox::query()->find($get('cash_box_id'))->currency ?? '…']))
                ->numeric()->minValue(0)->required()
                ->helperText($purchase ? __('Open on this invoice: :amount', ['amount' => Display::money(app(PurchaseService::class)->open($purchase), $purchase->currency)]) : __('Allocated to the oldest open invoices first.')),
            Select::make('method')->label(__('Method'))->options(PaymentMethod::options())->default(PaymentMethod::BankWire->value)->required()->native(false),
            TextInput::make('reference')->label(__('Reference (wire / hawala no.)'))->maxLength(120)->extraInputAttributes(['dir' => 'ltr']),
            DatePicker::make('paid_at')->label(__('Paid on'))->native(false)->displayFormat('Y-m-d')->default(fn () => now(config('orthotrack.timezone'))->toDateString())->required(),
            Textarea::make('notes')->label(__('Notes'))->rows(2),
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function pay(Supplier $supplier, array $data, ?Action $action, ?Purchase $purchase = null): ?SupplierPayment
    {
        $payload = [
            'amount' => (string) $data['amount'],
            'method' => $data['method'] ?? null,
            'reference' => $data['reference'] ?? null,
            'paid_at' => $data['paid_at'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];

        if ($purchase !== null) {
            $payload['allocations'] = [$purchase->id => app(PurchaseService::class)->open($purchase)];
        }

        $payment = ServiceAction::run($action, fn ($u) => app(SupplierPaymentService::class)->pay($supplier, CashBox::query()->findOrFail($data['cash_box_id']), $payload, $u));

        if ($payment !== null) {
            Notification::make()
                ->title($payment->status === SupplierPaymentStatus::PendingApproval
                    ? __('Payment :number is waiting for approval.', ['number' => $payment->number])
                    : __('Payment :number recorded.', ['number' => $payment->number]))
                ->success()->send();
        }

        return $payment;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['supplier', 'cashBox.branch', 'allocations.purchase']))
            ->defaultSort('paid_at', 'desc')
            ->columns([
                CodeColumn::make('number')->label(__('Number'))->searchable(),
                TextColumn::make('supplier.name')->label(__('Supplier'))->searchable(),
                TextColumn::make('amount')->label(__('Amount'))->alignEnd()->weight('semibold')
                    ->formatStateUsing(fn ($state, SupplierPayment $r) => Display::money($state, $r->currency))
                    ->description(fn (SupplierPayment $r) => $r->cashBox->label()),
                TextColumn::make('invoices')->label(__('Invoices'))->state(fn (SupplierPayment $r) => $r->allocations->map(fn (SupplierPaymentAllocation $a) => $a->purchase->supplier_invoice_number)->implode(', ') ?: '—')->extraAttributes(['dir' => 'ltr'])->wrap(),
                TextColumn::make('method')->label(__('Method'))->badge()->color('gray'),
                TextColumn::make('fx_gain_loss_usd')->label(__('FX gain / loss'))->alignEnd()->toggleable()
                    ->formatStateUsing(fn ($state) => Display::money($state, 'USD'))->color(fn ($state) => (float) $state < 0 ? 'danger' : 'gray'),
                TextColumn::make('paid_at')->label(__('Paid on'))->formatStateUsing(fn ($state) => Display::date($state))->sortable(),
                TextColumn::make('status')->label(__('Status'))->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(SupplierPaymentStatus::options()),
                SelectFilter::make('supplier_id')->label(__('Supplier'))->options(fn () => Supplier::options())->searchable(),
            ])
            ->recordActions([
                ViewAction::make()->schema(fn (Schema $schema) => static::infolist($schema)),
                Action::make('void')->label(__('Void'))->icon(Heroicon::OutlinedXMark)->color('danger')
                    ->visible(fn (SupplierPayment $r) => $r->status !== SupplierPaymentStatus::Voided && $r->status !== SupplierPaymentStatus::Rejected && ServiceAction::user()->can('supplier_payments.create'))
                    ->schema([Textarea::make('reason')->label(__('Reason'))->required()->rows(2)])
                    ->action(fn (SupplierPayment $record, array $data, Action $action) => ServiceAction::run($action, fn ($u) => app(SupplierPaymentService::class)->void($record, $data['reason'], $u), __('Payment voided — the money is back in the box.'))),
            ])
            ->emptyStateHeading(__('No supplier payments yet'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(['default' => 2, 'md' => 3])->schema([
                TextEntry::make('number')->label(__('Number'))->fontFamily(FontFamily::Mono)->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('supplier.name')->label(__('Supplier')),
                TextEntry::make('status')->label(__('Status'))->badge(),
                TextEntry::make('amount')->label(__('Amount'))->state(fn (SupplierPayment $r) => Display::money($r->amount, $r->currency)),
                TextEntry::make('amount_usd')->label(__('In USD'))->state(fn (SupplierPayment $r) => Display::money($r->amount_usd, 'USD')),
                TextEntry::make('reference')->label(__('Reference'))->placeholder('—')->fontFamily(FontFamily::Mono)->extraAttributes(['dir' => 'ltr']),
                LocalDateTimeEntry::make('approved_at')->label(__('Approved'))->placeholder('—'),
                TextEntry::make('notes')->label(__('Notes'))->placeholder('—')->columnSpan(2),
            ]),
            RepeatableEntry::make('allocations')->label(__('Paid against'))->columnSpanFull()->columns(4)->schema([
                TextEntry::make('purchase.supplier_invoice_number')->label(__('Invoice'))->fontFamily(FontFamily::Mono)->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('amount_in_purchase_currency')->label(__('Amount'))->state(fn (SupplierPaymentAllocation $a) => Display::money($a->amount_in_purchase_currency, $a->purchase->currency)),
                TextEntry::make('rate_used')->label(__('Rate'))->formatStateUsing(fn ($state) => Display::number($state, 4)),
                TextEntry::make('fx_gain_loss_usd')->label(__('FX gain / loss'))->formatStateUsing(fn ($state) => Display::money($state, 'USD')),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageSupplierPayments::route('/')];
    }
}
