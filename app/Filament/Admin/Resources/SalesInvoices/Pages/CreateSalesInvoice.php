<?php

namespace App\Filament\Admin\Resources\SalesInvoices\Pages;

use App\Enums\PaymentTerms;
use App\Exceptions\Domain\MoneyRuleViolation;
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
use App\Support\Locale\Display;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class CreateSalesInvoice extends CreateRecord
{
    protected static string $resource = SalesInvoiceResource::class;

    protected static bool $canCreateAnother = false;

    public function form(Schema $schema): Schema
    {
        $credit = app(SettingsRepository::class)->bool('features.credit_sales');
        $isCredit = fn (Get $get) => $get('payment_terms') === PaymentTerms::Credit->value;
        $isSplit = fn (Get $get) => $get('pricing') === SalesService::SPLIT;

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
                ToggleButtons::make('pricing')->label(__('Price'))->inline()->live()->required()
                    ->options([SalesService::PER_ITEM => __('Per item'), SalesService::SPLIT => __('One price, split by %')])
                    ->default(SalesService::PER_ITEM)
                    ->helperText(fn (Get $get) => $isSplit($get) ? __('Type one price for all the pieces. Each piece gets the percentage of what it cost us; you can change the percentages.') : null),
                TextInput::make('sale_total')->label(__('Price for all the pieces'))->numeric()->minValue(0)->live(onBlur: true)
                    ->visible($isSplit)->required($isSplit)->extraInputAttributes(['dir' => 'ltr']),
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
                        TextInput::make('unit_price')->label(__('Price (empty = list price)'))->numeric()->minValue(0)->extraInputAttributes(['dir' => 'ltr'])
                            ->hidden(fn (Get $get) => $get('../../pricing') === SalesService::SPLIT),
                        TextInput::make('share')->label(__('Share % (empty = cost share)'))->numeric()->minValue(0)->maxValue(100)->live(onBlur: true)->extraInputAttributes(['dir' => 'ltr'])
                            ->visible(fn (Get $get) => $get('../../pricing') === SalesService::SPLIT),
                    ])
                    ->live(),
                Placeholder::make('split_preview')->label(__('How the price is split'))->visible($isSplit)
                    ->content(fn (Get $get) => $this->splitPreview($get)),
                TextInput::make('discount')->label(__('Discount'))->numeric()->minValue(0)->default(0)->hidden($isSplit),
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
                'pricing' => $data['pricing'] ?? SalesService::PER_ITEM,
                'sale_total' => $data['sale_total'] ?? null,
                'discount' => ($data['pricing'] ?? null) === SalesService::SPLIT ? 0 : ($data['discount'] ?? 0),
                'override_reason' => $data['override_reason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'items' => array_values($data['items'] ?? []),
            ], ServiceAction::user());
        } catch (StockRuleViolation $e) {
            throw ValidationException::withMessages(['data.'.($e->field ?? 'items') => $e->getMessage()]);
        }
    }

    /**
     * Live table of the split: piece, cost share, chosen share, amount (cost only for people
     * who may see it).
     */
    private function splitPreview(Get $get): HtmlString|string
    {
        $branchId = $get('branch_id');
        $items = array_values(array_filter($get('items') ?? [], fn ($i) => filled($i['raw'] ?? null) || filled($i['product_id'] ?? null)));

        if (blank($branchId) || $items === []) {
            return __('Add the items to see the split.');
        }

        $currency = (string) ($get('currency') ?: 'USD');

        try {
            $rows = app(SalesService::class)->quoteSplit(Branch::query()->findOrFail($branchId), $items, $currency, $get('sale_total'));
        } catch (MoneyRuleViolation|StockRuleViolation $e) {
            return $e->getMessage();
        }

        $user = ServiceAction::user();
        $canSeeCost = $user->canInBranch('stock.view_cost', (int) $branchId);
        $head = '<tr class="text-gray-500"><th class="py-1 pe-3 text-start font-medium">'.e(__('Item')).'</th>'
            .($canSeeCost ? '<th class="px-3 py-1 text-end font-medium">'.e(__('Cost')).'</th>' : '')
            .'<th class="px-3 py-1 text-end font-medium">'.e(__('Cost share')).'</th>'
            .'<th class="px-3 py-1 text-end font-medium">'.e(__('Share')).'</th>'
            .'<th class="ps-3 py-1 text-end font-medium">'.e(__('Gets')).'</th></tr>';
        $body = '';

        foreach ($rows as $row) {
            $body .= '<tr class="border-t border-gray-100 dark:border-white/5">'
                .'<td class="py-1 pe-3"><span class="ot-code">'.e($row['product']->sku).'</span> × '.e(Display::number($row['quantity'])).'</td>'
                .($canSeeCost ? '<td class="px-3 py-1 text-end" dir="ltr">'.e(Display::money($row['cost'], $currency)).'</td>' : '')
                .'<td class="px-3 py-1 text-end" dir="ltr">'.e(Display::number($row['cost_share'], 2)).' %</td>'
                .'<td class="px-3 py-1 text-end font-semibold" dir="ltr">'.e(Display::number($row['share'], 2)).' %</td>'
                .'<td class="ps-3 py-1 text-end font-semibold" dir="ltr">'.($row['amount'] !== null ? e(Display::money($row['amount'], $currency)) : '—').'</td></tr>';
        }

        return new HtmlString('<div class="overflow-x-auto"><table class="w-full text-sm">'.$head.$body.'</table></div>');
    }

    protected function getRedirectUrl(): string
    {
        return SalesInvoiceResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
