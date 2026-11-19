<?php

namespace App\Filament\Admin\Resources\CustomerReturns\Pages;

use App\Enums\ReturnCondition;
use App\Enums\ReturnReason;
use App\Exceptions\Domain\StockRuleViolation;
use App\Filament\Admin\Resources\CustomerReturns\CustomerReturnResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StockUnit;
use App\Services\Movement\ReturnService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateCustomerReturn extends CreateRecord
{
    protected static string $resource = CustomerReturnResource::class;

    protected static bool $canCreateAnother = false;

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('branch_id')->label(__('Back into station'))->options(fn () => ServiceAction::branchOptions('returns.customer'))->required()->native(false),
            Select::make('customer_id')->label(__('Hospital / customer'))->options(fn () => Customer::options())->searchable()->native(false),
            Repeater::make('lines')
                ->label(__('Items'))
                ->columnSpanFull()
                ->columns(4)
                ->minItems(1)
                ->defaultItems(1)
                ->addActionLabel(__('Add an item'))
                ->schema([
                    TextInput::make('serial')->label(__('Serial (for serial units)'))->extraInputAttributes(['dir' => 'ltr', 'class' => 'ot-code', 'data-scan-field' => true])->columnSpan(2),
                    Select::make('product_id')->label(__('or lot product'))->searchable()->columnSpan(2)
                        ->getSearchResultsUsing(fn (string $s) => Product::query()->where('tracking_type', 'lot')->search($s)->limit(30)->get()->mapWithKeys(fn (Product $p) => [$p->id => $p->label()]))
                        ->getOptionLabelUsing(fn ($v) => Product::query()->find($v)?->label()),
                    TextInput::make('lot')->label(__('Lot'))->extraInputAttributes(['dir' => 'ltr']),
                    DatePicker::make('expiry_date')->label(__('Expiry'))->native(false)->displayFormat('Y-m-d'),
                    TextInput::make('quantity')->label(__('Quantity (lots)'))->numeric()->integer()->minValue(1)->default(1),
                    Select::make('reason')->label(__('Reason'))->options(ReturnReason::options())->default(ReturnReason::Unused->value)->required()->native(false),
                    Select::make('condition')->label(__('Condition'))->options(ReturnCondition::options())->default(ReturnCondition::Good->value)->required()->native(false)->columnSpan(2),
                ]),
            Textarea::make('notes')->label(__('Notes'))->rows(2)->columnSpanFull(),
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $items = [];

        foreach ($data['lines'] ?? [] as $line) {
            $base = ['reason' => $line['reason'], 'condition' => $line['condition']];

            if (filled($line['serial'] ?? null)) {
                $unit = StockUnit::query()->where('serial', trim($line['serial']))->first()
                    ?? throw ValidationException::withMessages(['data.lines' => __('Serial :serial is not in the system.', ['serial' => $line['serial']])]);
                $items[] = $base + ['stock_unit_id' => $unit->id];
            } elseif (filled($line['product_id'] ?? null)) {
                $items[] = $base + ['product_id' => (int) $line['product_id'], 'lot' => $line['lot'] ?? null, 'expiry_date' => $line['expiry_date'] ?? null, 'quantity' => (int) ($line['quantity'] ?? 1)];
            }
        }

        try {
            return app(ReturnService::class)->receiveFromCustomer(
                Branch::query()->findOrFail($data['branch_id']),
                $items,
                ServiceAction::user(),
                filled($data['customer_id'] ?? null) ? Customer::query()->find($data['customer_id']) : null,
                $data['notes'] ?? null,
            );
        } catch (StockRuleViolation $e) {
            throw ValidationException::withMessages(['data.lines' => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return CustomerReturnResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
