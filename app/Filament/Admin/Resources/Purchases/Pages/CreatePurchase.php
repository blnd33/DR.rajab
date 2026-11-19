<?php

namespace App\Filament\Admin\Resources\Purchases\Pages;

use App\Exceptions\Domain\StockRuleViolation;
use App\Filament\Admin\Resources\Purchases\PurchaseResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\Branch;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\Purchasing\PurchaseService;
use App\Support\Locale\Display;
use Brick\Math\BigDecimal;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreatePurchase extends CreateRecord
{
    protected static string $resource = PurchaseResource::class;

    protected static bool $canCreateAnother = false;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Invoice'))->columnSpanFull()->columns(['default' => 1, 'md' => 3])->schema([
                Select::make('supplier_id')->label(__('Supplier'))->options(fn () => Supplier::options())->searchable()->required()->native(false)->live()
                    ->afterStateUpdated(fn ($state, Set $set) => $set('currency', Supplier::query()->find($state)->default_currency ?? 'USD')),
                TextInput::make('supplier_invoice_number')->label(__('Supplier invoice no.'))->required()->maxLength(64)->extraInputAttributes(['dir' => 'ltr', 'class' => 'ot-code']),
                Select::make('branch_id')->label(__('Receiving station'))->options(fn () => ServiceAction::branchOptions('purchases.create'))->required()->native(false),
                DatePicker::make('invoice_date')->label(__('Invoice date'))->native(false)->displayFormat('Y-m-d')->default(fn () => now(config('orthotrack.timezone'))->toDateString())->required(),
                DatePicker::make('due_date')->label(__('Due date'))->native(false)->displayFormat('Y-m-d')
                    ->helperText(__('Empty = invoice date + the supplier\'s payment terms.')),
                Select::make('currency')->label(__('Currency'))->options(fn () => Currency::options())->default('USD')->required()->native(false)->live(),
            ]),
            Section::make(__('Lines'))->columnSpanFull()->schema([
                Repeater::make('lines')
                    ->hiddenLabel()
                    ->columns(['default' => 2, 'md' => 6])
                    ->minItems(1)
                    ->defaultItems(1)
                    ->reorderable(false)
                    ->addActionLabel(__('Add a line'))
                    ->live()
                    ->schema([
                        Select::make('product_id')->label(__('Product'))->required()->searchable()->columnSpan(['default' => 2, 'md' => 2])
                            ->getSearchResultsUsing(fn (string $search) => Product::query()->active()->search($search)->limit(30)->get()->mapWithKeys(fn (Product $p) => [$p->id => $p->label()]))
                            ->getOptionLabelUsing(fn ($value) => Product::query()->find($value)?->label())
                            ->live()
                            ->afterStateUpdated(fn ($state, Set $set) => $set('unit_price', Product::query()->find($state)?->purchase_price)),
                        TextInput::make('quantity')->label(__('Quantity'))->numeric()->integer()->minValue(1)->default(1)->required(),
                        TextInput::make('unit_price')->label(__('Unit price'))->numeric()->minValue(0)->required()->extraInputAttributes(['dir' => 'ltr']),
                        TextInput::make('expected_lot')->label(__('Lot (if known)'))->maxLength(64)->extraInputAttributes(['dir' => 'ltr', 'class' => 'ot-code']),
                        DatePicker::make('expected_expiry')->label(__('Expiry (if known)'))->native(false)->displayFormat('Y-m-d'),
                    ]),
                TextInput::make('discount')->label(__('Invoice discount'))->numeric()->minValue(0)->default(0)->live(onBlur: true)
                    ->helperText(__('Spread over the lines, so the true unit cost is what you paid.')),
                Placeholder::make('total_preview')->label(__('Total'))
                    ->content(fn (Get $get) => Display::money((string) $this->total($get('lines') ?? [], $get('discount')), (string) ($get('currency') ?: 'USD'))),
            ]),
            Textarea::make('notes')->label(__('Notes'))->rows(2)->columnSpanFull(),
            Toggle::make('confirm_now')->label(__('Confirm now (adds it to the supplier\'s statement)'))->default(true),
        ]);
    }

    /**
     * @param  array<array-key, array<string, mixed>>  $lines
     */
    private function total(array $lines, mixed $discount): BigDecimal
    {
        $sum = BigDecimal::zero();

        foreach ($lines as $line) {
            if (is_numeric($line['quantity'] ?? null) && is_numeric($line['unit_price'] ?? null)) {
                $sum = $sum->plus(BigDecimal::of((string) $line['unit_price'])->multipliedBy((int) $line['quantity']));
            }
        }

        $total = $sum->minus(is_numeric($discount) ? (string) $discount : '0');

        return $total->isNegative() ? BigDecimal::zero() : $total->toScale(2);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $service = app(PurchaseService::class);
        $user = ServiceAction::user();

        try {
            $purchase = $service->create(
                Supplier::query()->findOrFail($data['supplier_id']),
                Branch::query()->findOrFail($data['branch_id']),
                [
                    'supplier_invoice_number' => $data['supplier_invoice_number'],
                    'invoice_date' => $data['invoice_date'],
                    'due_date' => $data['due_date'] ?? null,
                    'currency' => $data['currency'],
                    'discount' => $data['discount'] ?? 0,
                    'notes' => $data['notes'] ?? null,
                    'lines' => array_values($data['lines'] ?? []),
                ],
                $user,
            );

            return ($data['confirm_now'] ?? false) ? $service->confirm($purchase, $user) : $purchase;
        } catch (StockRuleViolation $e) {
            throw ValidationException::withMessages(['data.'.($e->field ?? 'lines') => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return PurchaseResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
