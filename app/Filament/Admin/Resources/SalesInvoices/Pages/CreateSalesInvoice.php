<?php

namespace App\Filament\Admin\Resources\SalesInvoices\Pages;

use App\Enums\PaymentTerms;
use App\Exceptions\Domain\StockRuleViolation;
use App\Filament\Admin\Resources\SalesInvoices\SalesInvoiceResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\Branch;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Product;
use App\Services\Money\CashBoxService;
use App\Services\Sales\SalesService;
use App\Services\Settings\SettingsRepository;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateSalesInvoice extends CreateRecord
{
    protected static string $resource = SalesInvoiceResource::class;

    protected static bool $canCreateAnother = false;

    public function form(Schema $schema): Schema
    {
        $credit = app(SettingsRepository::class)->bool('features.credit_sales');
        $isCredit = fn (Get $get) => $get('payment_terms') === PaymentTerms::Credit->value;

        return $schema->components([
            Section::make(__('Sale'))->columnSpanFull()->columns(['default' => 1, 'md' => 3])->schema([
                Select::make('branch_id')->label(__('Branch'))->options(fn () => ServiceAction::branchOptions('sales.create'))->required()->native(false)->live(),
                Select::make('currency')->label(__('Currency'))->options(fn () => Currency::options())->default('USD')->required()->native(false)->live(),
                Select::make('payment_terms')->label(__('Payment'))->native(false)->live()->required()
                    ->options(fn () => $credit ? PaymentTerms::options() : [PaymentTerms::Cash->value => PaymentTerms::Cash->getLabel()])
                    ->default(PaymentTerms::Cash->value),
                Select::make('customer_id')->label(__('Hospital / customer'))->options(fn () => Customer::options())->searchable()->native(false)
                    ->required($isCredit),
                TextInput::make('walk_in_name')->label(__('or walk-in buyer'))->maxLength(150)->hidden($isCredit),
                Select::make('cash_box_id')->label(__('Money into'))->native(false)->required(fn (Get $get) => ! $isCredit($get))->hidden($isCredit)
                    ->options(fn (Get $get) => filled($get('branch_id'))
                        ? app(CashBoxService::class)->options(ServiceAction::user(), 'sales.create', (string) $get('currency'), (int) $get('branch_id'))
                        : []),
                DatePicker::make('due_date')->label(__('Due date'))->native(false)->displayFormat('Y-m-d')->visible($isCredit)->default(fn () => now(config('orthotrack.timezone'))->addDays(30)->toDateString()),
            ]),
            Section::make(__('Items'))->columnSpanFull()->schema([
                Repeater::make('items')
                    ->hiddenLabel()
                    ->columns(['default' => 2, 'md' => 5])
                    ->minItems(1)
                    ->defaultItems(1)
                    ->reorderable(false)
                    ->addActionLabel(__('Add an item'))
                    ->schema([
                        TextInput::make('raw')->label(__('Scan serial / barcode'))->columnSpan(2)
                            ->extraInputAttributes(['dir' => 'ltr', 'class' => 'ot-code', 'data-scan-field' => true]),
                        Select::make('product_id')->label(__('or lot product'))->searchable()->columnSpan(['default' => 2, 'md' => 1])
                            ->getSearchResultsUsing(fn (string $s) => Product::query()->active()->where('tracking_type', 'lot')->search($s)->limit(30)->get()->mapWithKeys(fn (Product $p) => [$p->id => $p->label()]))
                            ->getOptionLabelUsing(fn ($v) => Product::query()->find($v)?->label()),
                        TextInput::make('quantity')->label(__('Quantity'))->numeric()->integer()->minValue(1)->default(1),
                        TextInput::make('unit_price')->label(__('Price (empty = list price)'))->numeric()->minValue(0)->extraInputAttributes(['dir' => 'ltr']),
                    ]),
                TextInput::make('discount')->label(__('Discount'))->numeric()->minValue(0)->default(0),
                TextInput::make('override_reason')->label(__('Reason if an item is near expiry or not first-to-expire'))->maxLength(255),
            ]),
            Textarea::make('notes')->label(__('Notes'))->rows(2)->columnSpanFull(),
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(SalesService::class)->sell(Branch::query()->findOrFail($data['branch_id']), [
                'currency' => $data['currency'],
                'payment_terms' => $data['payment_terms'],
                'customer_id' => $data['customer_id'] ?? null,
                'walk_in_name' => $data['walk_in_name'] ?? null,
                'cash_box_id' => $data['cash_box_id'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'discount' => $data['discount'] ?? 0,
                'override_reason' => $data['override_reason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'items' => array_values($data['items'] ?? []),
            ], ServiceAction::user());
        } catch (StockRuleViolation $e) {
            throw ValidationException::withMessages(['data.'.($e->field ?? 'items') => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return SalesInvoiceResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
