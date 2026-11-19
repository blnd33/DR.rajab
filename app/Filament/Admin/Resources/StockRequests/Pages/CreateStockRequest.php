<?php

namespace App\Filament\Admin\Resources\StockRequests\Pages;

use App\Enums\RequestPriority;
use App\Exceptions\Domain\StockRuleViolation;
use App\Filament\Admin\Resources\StockRequests\StockRequestResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\Branch;
use App\Models\LowStock;
use App\Models\Product;
use App\Services\Movement\StockRequestService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * New stock request. ?branch=ID&low=1 pre-fills everything below minimum in that branch
 * (the "Request from another branch" button on Low stock).
 */
class CreateStockRequest extends CreateRecord
{
    protected static string $resource = StockRequestResource::class;

    protected static bool $canCreateAnother = false;

    public function mount(): void
    {
        parent::mount();

        $branchId = (int) request()->query('branch');

        if ($branchId > 0 && request()->boolean('low')) {
            $lines = LowStock::query()->where('branch_id', $branchId)
                ->when(request()->query('product'), fn ($q, $p) => $q->where('product_id', $p))
                ->get()
                ->map(fn (LowStock $row) => ['product_id' => $row->product_id, 'quantity' => $row->reorder_qty ?? $row->shortage])
                ->values()->all();

            $this->form->fill(['requesting_branch_id' => $branchId, 'priority' => RequestPriority::Normal->value, 'lines' => $lines]);
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('requesting_branch_id')->label(__('For station'))->options(fn () => ServiceAction::branchOptions('stock_requests.create'))->required()->live()->native(false),
            Select::make('preferred_source_branch_id')->label(__('Ask which station? (optional)'))
                ->options(fn ($get) => Branch::query()->active()->where('id', '<>', (int) $get('requesting_branch_id'))->orderBy('code')->get()->mapWithKeys(fn ($b) => [$b->id => $b->label()]))
                ->placeholder(__('Any — the approver decides'))->native(false),
            ToggleButtons::make('priority')->label(__('Priority'))->options(RequestPriority::options())->default(RequestPriority::Normal->value)->inline()->required(),
            DatePicker::make('needed_by')->label(__('Needed by (optional)'))->native(false)->displayFormat('Y-m-d'),
            Repeater::make('lines')
                ->label(__('Products'))
                ->columnSpanFull()
                ->columns(3)
                ->minItems(1)
                ->defaultItems(1)
                ->addActionLabel(__('Add a product'))
                ->schema([
                    Select::make('product_id')->label(__('Product'))->required()->searchable()->columnSpan(2)
                        ->getSearchResultsUsing(fn (string $search) => Product::query()->active()->search($search)->limit(30)->get()->mapWithKeys(fn (Product $p) => [$p->id => $p->label()]))
                        ->getOptionLabelUsing(fn ($value) => Product::query()->find($value)?->label()),
                    TextInput::make('quantity')->label(__('Quantity'))->numeric()->integer()->minValue(1)->required(),
                ]),
            Textarea::make('notes')->label(__('Notes'))->rows(2)->columnSpanFull(),
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(StockRequestService::class)->create(
                Branch::query()->findOrFail($data['requesting_branch_id']),
                array_values($data['lines'] ?? []),
                ServiceAction::user(),
                filled($data['preferred_source_branch_id'] ?? null) ? Branch::query()->find($data['preferred_source_branch_id']) : null,
                RequestPriority::from($data['priority']),
                $data['needed_by'] ?? null,
                $data['notes'] ?? null,
            );
        } catch (StockRuleViolation $e) {
            throw ValidationException::withMessages(['data.lines' => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return StockRequestResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
