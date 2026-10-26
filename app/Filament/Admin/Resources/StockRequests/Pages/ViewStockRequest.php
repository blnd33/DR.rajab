<?php

namespace App\Filament\Admin\Resources\StockRequests\Pages;

use App\Enums\StockRequestStatus;
use App\Filament\Admin\Resources\StockRequests\StockRequestResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\Branch;
use App\Models\StockRequest;
use App\Models\StockRequestItem;
use App\Services\Movement\StockRequestService;
use App\Support\Locale\Display;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;

class ViewStockRequest extends ViewRecord
{
    protected static string $resource = StockRequestResource::class;

    public function getTitle(): string
    {
        return __('Stock request :number', ['number' => $this->stockRequest()->number]);
    }

    protected function getHeaderActions(): array
    {
        $canDecide = fn () => $this->stockRequest()->status === StockRequestStatus::Pending
            && ServiceAction::user()->canInBranch('stock_requests.approve', $this->stockRequest()->requesting_branch_id);

        return [
            Action::make('approve')
                ->label(__('Approve'))
                ->icon(Heroicon::OutlinedCheck)
                ->color('success')
                ->visible($canDecide)
                ->modalWidth('3xl')
                ->modalDescription(__('Set the quantity to send and the sending branch per product (0 = not approved). One draft transfer is created per sending branch.'))
                ->fillForm(fn () => [
                    'lines' => $this->stockRequest()->items->mapWithKeys(fn (StockRequestItem $i) => [$i->id => [
                        'quantity_approved' => $i->quantity_requested,
                        'source_branch_id' => $i->source_branch_id,
                    ]])->all(),
                ])
                ->schema(fn () => [
                    ...$this->stockRequest()->items->map(fn (StockRequestItem $i) => Grid::make(3)->schema([
                        TextInput::make("lines.{$i->id}.quantity_approved")
                            ->label($i->product->sku.' — '.__('asked :qty', ['qty' => Display::number($i->quantity_requested)]))
                            ->numeric()->integer()->minValue(0)->required(),
                        Select::make("lines.{$i->id}.source_branch_id")
                            ->label(__('From branch'))
                            ->options(fn () => $this->sourceOptions($i))
                            ->native(false)
                            ->columnSpan(2),
                    ]))->values()->all(),
                    Textarea::make('note')->label(__('Note'))->rows(2),
                ])
                ->action(function (array $data, Action $action) {
                    ServiceAction::run($action, fn ($u) => app(StockRequestService::class)->approve($this->stockRequest(), $data['lines'] ?? [], $u, $data['note'] ?? null), __('Approved. The sending branches were told.'));
                    $this->refreshRecord();
                }),

            Action::make('reject')
                ->label(__('Reject'))
                ->icon(Heroicon::OutlinedXMark)
                ->color('danger')
                ->visible($canDecide)
                ->schema([Textarea::make('note')->label(__('Reason'))->required()->rows(2)])
                ->action(function (array $data, Action $action) {
                    ServiceAction::run($action, fn ($u) => app(StockRequestService::class)->reject($this->stockRequest(), $data['note'], $u), __('Request rejected.'));
                    $this->refreshRecord();
                }),
        ];
    }

    /**
     * Open branches with how much valid stock each has of this product.
     *
     * @return array<int, string>
     */
    private function sourceOptions(StockRequestItem $item): array
    {
        $suggested = app(StockRequestService::class)->suggestSource($item->product, $item->quantity_requested, $this->stockRequest()->requesting_branch_id);

        return Branch::query()->active()->where('id', '<>', $this->stockRequest()->requesting_branch_id)->orderBy('code')->get()
            ->mapWithKeys(fn (Branch $b) => [$b->id => $b->label().($b->id === $suggested ? ' — '.__('suggested') : '')])
            ->all();
    }

    public function refreshRecord(): void
    {
        $this->record = $this->stockRequest()->fresh(['items.product']);
        $this->dispatch('request-updated');
    }

    private function stockRequest(): StockRequest
    {
        /** @var StockRequest $record */
        $record = $this->getRecord();
        $record->loadMissing('items.product');

        return $record;
    }
}
