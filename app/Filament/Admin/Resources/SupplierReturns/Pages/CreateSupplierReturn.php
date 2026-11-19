<?php

namespace App\Filament\Admin\Resources\SupplierReturns\Pages;

use App\Enums\LocationType;
use App\Enums\SupplierReturnReason;
use App\Exceptions\Domain\StockRuleViolation;
use App\Filament\Admin\Resources\SupplierReturns\SupplierReturnResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\Branch;
use App\Models\StockBatch;
use App\Models\StockUnit;
use App\Models\Supplier;
use App\Services\Movement\ReturnService;
use App\Support\Locale\Display;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateSupplierReturn extends CreateRecord
{
    protected static string $resource = SupplierReturnResource::class;

    protected static bool $canCreateAnother = false;

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('supplier_id')->label(__('Supplier'))->options(fn () => Supplier::options())->searchable()->required()->native(false),
            Select::make('branch_id')->label(__('From station'))->options(fn () => ServiceAction::branchOptions('returns.supplier'))->required()->live()->native(false),
            Repeater::make('lines')
                ->label(__('Items'))
                ->columnSpanFull()
                ->columns(4)
                ->minItems(1)
                ->defaultItems(1)
                ->addActionLabel(__('Add an item'))
                ->schema([
                    TextInput::make('serial')->label(__('Serial (for serial units)'))->extraInputAttributes(['dir' => 'ltr', 'class' => 'ot-code', 'data-scan-field' => true]),
                    Select::make('batch_id')->label(__('or lot'))
                        ->options(fn (Get $get) => StockBatch::query()->with('product')
                            ->where('branch_id', (int) $get('../../branch_id'))->where('location_type', LocationType::Shelf)->where('quantity', '>', 0)
                            ->orderBy('product_id')->get()
                            ->mapWithKeys(fn (StockBatch $b) => [$b->id => $b->product->sku.' · '.$b->codeLabel().' · '.Display::date($b->expiry_date).' · '.__(':qty on shelf', ['qty' => Display::number($b->quantity)])]))
                        ->searchable()->native(false),
                    TextInput::make('quantity')->label(__('Quantity (lots)'))->numeric()->integer()->minValue(1)->default(1),
                    Select::make('reason')->label(__('Reason'))->options(SupplierReturnReason::options())->default(SupplierReturnReason::Defective->value)->required()->native(false),
                    TextInput::make('unit_credit')->label(__('Credit per unit (empty = cost)'))->numeric()->minValue(0)->extraInputAttributes(['dir' => 'ltr']),
                ]),
            Textarea::make('notes')->label(__('Notes'))->rows(2)->columnSpanFull(),
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $items = [];

        foreach ($data['lines'] ?? [] as $line) {
            $base = ['reason' => $line['reason'], 'unit_credit' => $line['unit_credit'] ?? null];

            if (filled($line['serial'] ?? null)) {
                $unit = StockUnit::query()->where('serial', trim($line['serial']))->first()
                    ?? throw ValidationException::withMessages(['data.lines' => __('Serial :serial is not in the system.', ['serial' => $line['serial']])]);
                $items[] = $base + ['stock_unit_id' => $unit->id];
            } elseif (filled($line['batch_id'] ?? null)) {
                $items[] = $base + ['stock_batch_id' => (int) $line['batch_id'], 'quantity' => (int) ($line['quantity'] ?? 1)];
            }
        }

        try {
            return app(ReturnService::class)->createSupplierReturn(
                Supplier::query()->findOrFail($data['supplier_id']),
                Branch::query()->findOrFail($data['branch_id']),
                $items,
                ServiceAction::user(),
                $data['notes'] ?? null,
            );
        } catch (StockRuleViolation $e) {
            throw ValidationException::withMessages(['data.lines' => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return SupplierReturnResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
