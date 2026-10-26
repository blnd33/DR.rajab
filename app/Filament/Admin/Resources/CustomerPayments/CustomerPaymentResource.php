<?php

namespace App\Filament\Admin\Resources\CustomerPayments;

use App\Enums\CustomerPaymentStatus;
use App\Enums\PaymentMethod;
use App\Filament\Admin\Resources\CustomerPayments\Pages\ManageCustomerPayments;
use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Filament\Admin\Tables\Columns\LocalDateTimeColumn;
use App\Models\CashBox;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentAllocation;
use App\Services\Money\CashBoxService;
use App\Services\Money\PartyLedgerService;
use App\Services\Sales\CustomerPaymentService;
use App\Services\Settings\SettingsRepository;
use App\Support\Locale\Display;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Money collected from hospitals for credit sales (Module 9, P4-12). Shown only while
 * credit sales are switched on, or when payments already exist.
 */
class CustomerPaymentResource extends Resource
{
    protected static ?string $model = CustomerPayment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?int $navigationSort = 55;

    public static function getNavigationGroup(): ?string
    {
        return __('Finance');
    }

    public static function getModelLabel(): string
    {
        return __('Customer payment');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Customer payments');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation()
            && (app(SettingsRepository::class)->bool('features.credit_sales') || CustomerPayment::query()->exists());
    }

    public static function getEloquentQuery(): Builder
    {
        $boxes = ServiceAction::scoped(CashBox::query(), 'sales.view_any', ['branch_id'])->select('id');

        return parent::getEloquentQuery()->whereIn('cash_box_id', $boxes);
    }

    public static function owedText(Customer $customer): string
    {
        $balances = app(PartyLedgerService::class)->balances($customer);

        return $balances === [] ? '—' : implode(' · ', array_map(fn ($c, $v) => Display::money($v, $c), array_keys($balances), $balances));
    }

    /**
     * @return list<Field>
     */
    public static function receiveSchema(bool $pickCustomer = false, ?int $branchId = null): array
    {
        return array_values(array_filter([
            $pickCustomer ? Select::make('customer_id')->label(__('Hospital / customer'))->options(fn () => Customer::options())->searchable()->required()->native(false)->live()
                ->helperText(fn (Get $get) => filled($get('customer_id')) ? __('Owes: :amount', ['amount' => static::owedText(Customer::query()->findOrFail($get('customer_id')))]) : null) : null,
            Select::make('cash_box_id')->label(__('Money into'))->required()->native(false)->live()
                ->options(fn () => app(CashBoxService::class)->options(ServiceAction::user(), 'customer_payments.create', branchId: $branchId)),
            TextInput::make('amount')->label(fn (Get $get) => __('Amount (:currency)', ['currency' => CashBox::query()->find($get('cash_box_id'))->currency ?? '…']))
                ->numeric()->minValue(0)->required()->helperText(__('Allocated to the oldest open invoices first.')),
            Select::make('method')->label(__('Method'))->options(PaymentMethod::options())->default(PaymentMethod::Cash->value)->required()->native(false),
            TextInput::make('reference')->label(__('Reference'))->maxLength(120)->extraInputAttributes(['dir' => 'ltr']),
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function receive(?Customer $customer, array $data, ?Action $action): ?CustomerPayment
    {
        $customer ??= Customer::query()->findOrFail($data['customer_id']);

        return ServiceAction::run($action, fn ($u) => app(CustomerPaymentService::class)->receive($customer, CashBox::query()->findOrFail($data['cash_box_id']), [
            'amount' => (string) $data['amount'],
            'method' => $data['method'] ?? null,
            'reference' => $data['reference'] ?? null,
        ], $u), __('Payment received.'));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['customer', 'cashBox.branch', 'allocations.invoice', 'receiver']))
            ->defaultSort('received_at', 'desc')
            ->columns([
                CodeColumn::make('number')->label(__('Number'))->searchable(),
                TextColumn::make('customer.name')->label(__('Hospital / customer')),
                TextColumn::make('amount')->label(__('Amount'))->alignEnd()->weight('semibold')
                    ->formatStateUsing(fn ($state, CustomerPayment $r) => Display::money($state, $r->currency))
                    ->description(fn (CustomerPayment $r) => $r->cashBox->label()),
                TextColumn::make('invoices')->label(__('Invoices'))->extraAttributes(['dir' => 'ltr'])->wrap()
                    ->state(fn (CustomerPayment $r) => $r->allocations->map(fn (CustomerPaymentAllocation $a) => $a->invoice->number)->implode(', ') ?: '—'),
                TextColumn::make('method')->label(__('Method'))->badge()->color('gray'),
                LocalDateTimeColumn::make('received_at')->label(__('Received')),
                TextColumn::make('status')->label(__('Status'))->badge()->description(fn (CustomerPayment $r) => $r->getAttribute('void_reason')),
            ])
            ->filters([SelectFilter::make('status')->label(__('Status'))->options(CustomerPaymentStatus::options())])
            ->recordActions([
                Action::make('void')->label(__('Void'))->icon(Heroicon::OutlinedXMark)->color('danger')
                    ->visible(fn (CustomerPayment $r) => $r->status === CustomerPaymentStatus::Posted && ServiceAction::user()->canInBranch('customer_payments.create', $r->cashBox->branch_id))
                    ->schema([Textarea::make('reason')->label(__('Reason'))->required()->rows(2)])
                    ->action(fn (CustomerPayment $record, array $data, Action $action) => ServiceAction::run($action, fn ($u) => app(CustomerPaymentService::class)->void($record, $data['reason'], $u), __('Payment voided.'))),
            ])
            ->emptyStateHeading(__('No customer payments yet'));
    }

    public static function getPages(): array
    {
        return ['index' => ManageCustomerPayments::route('/')];
    }
}
