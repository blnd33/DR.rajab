<?php

namespace App\Filament\Admin\Resources\Transfers\Pages;

use App\Enums\TransferStatus;
use App\Filament\Admin\Resources\Transfers\TransferResource;
use App\Filament\Admin\Support\RefreshesInfolist;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\Transfer;
use App\Models\TransferItem;
use App\Services\Movement\TransferService;
use App\Support\Locale\Display;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;

class ViewTransfer extends ViewRecord
{
    use RefreshesInfolist;

    protected static string $resource = TransferResource::class;

    public function getTitle(): string
    {
        return __('Transfer :number', ['number' => $this->transfer()->number]);
    }

    protected function getHeaderActions(): array
    {
        $user = ServiceAction::user();

        return [
            Action::make('send')
                ->label(__('Send'))
                ->icon(Heroicon::OutlinedTruck)
                ->color('primary')
                ->visible(fn () => $this->transfer()->status === TransferStatus::Draft && $user->canInBranch('transfers.send', $this->transfer()->from_branch_id))
                ->schema([
                    TextInput::make('override_reason')
                        ->label(__('Reason to send expired items (only if there are any)'))
                        ->visible(fn () => $user->canInBranch('stock.override_expired', $this->transfer()->from_branch_id)),
                ])
                ->modalDescription(__('Units become "in transit"; lot quantities leave the shelf of the sending branch.'))
                ->action(function (array $data, Action $action) {
                    ServiceAction::run($action, fn ($u) => app(TransferService::class)->send($this->transfer(), $u, $data['override_reason'] ?? null), __('Transfer sent.'));
                    $this->refreshRecord();
                }),

            Action::make('receive')
                ->label(__('Receive'))
                ->icon(Heroicon::OutlinedInboxArrowDown)
                ->color('success')
                ->visible(fn () => $this->transfer()->status === TransferStatus::InTransit && $user->canInBranch('transfers.receive', $this->transfer()->to_branch_id))
                ->modalDescription(__('Tick the units that arrived and confirm lot quantities. Anything missing becomes an issue for the admin.'))
                ->fillForm(fn () => [
                    'units' => $this->transfer()->items->whereNotNull('stock_unit_id')->pluck('stock_unit_id')->all(),
                    'lots' => $this->transfer()->items->whereNull('stock_unit_id')->mapWithKeys(fn (TransferItem $i) => [$i->id => $i->quantity_sent])->all(),
                ])
                ->schema(fn () => [
                    CheckboxList::make('units')
                        ->label(__('Serial units that arrived'))
                        ->options($this->transfer()->items->whereNotNull('stock_unit_id')->mapWithKeys(fn (TransferItem $i) => [$i->stock_unit_id => $i->stockUnit->serial.' — '.$i->product->sku])->all())
                        ->columns(2)
                        ->visible($this->transfer()->items->whereNotNull('stock_unit_id')->isNotEmpty()),
                    Section::make(__('Lot quantities received'))
                        ->visible($this->transfer()->items->whereNull('stock_unit_id')->isNotEmpty())
                        ->schema($this->transfer()->items->whereNull('stock_unit_id')->map(fn (TransferItem $i) => TextInput::make('lots.'.$i->id)
                            ->label($i->product->sku.' · '.__('Lot').' '.($i->fromBatch->lot ?? '—').' · '.__('sent :qty', ['qty' => Display::number($i->quantity_sent)]))
                            ->numeric()->integer()->minValue(0)->maxValue($i->quantity_sent)->required())->values()->all()),
                ])
                ->action(function (array $data, Action $action) {
                    ServiceAction::run($action, fn ($u) => app(TransferService::class)->receive(
                        $this->transfer(),
                        array_map('intval', $data['units'] ?? []),
                        array_map('intval', $data['lots'] ?? []),
                        $u,
                    ), __('Transfer received.'));
                    $this->refreshRecord();
                }),

            Action::make('cancel')
                ->label(__('Cancel transfer'))
                ->icon(Heroicon::OutlinedXMark)
                ->color('danger')
                ->visible(fn () => in_array($this->transfer()->status, [TransferStatus::Draft, TransferStatus::InTransit], true) && $user->canInBranch('transfers.cancel', $this->transfer()->from_branch_id))
                ->modalDescription(__('Items in transit go back to the sending branch.'))
                ->schema([Textarea::make('reason')->label(__('Reason'))->required()->rows(2)])
                ->action(function (array $data, Action $action) {
                    ServiceAction::run($action, fn ($u) => app(TransferService::class)->cancel($this->transfer(), $data['reason'], $u), __('Transfer cancelled.'));
                    $this->refreshRecord();
                }),
        ];
    }

    public function refreshRecord(): void
    {
        $this->record = $this->transfer()->fresh(['items.product', 'items.stockUnit', 'items.fromBatch']);
        $this->dispatch('transfer-updated');
    }

    private function transfer(): Transfer
    {
        /** @var Transfer $record */
        $record = $this->getRecord();
        $record->loadMissing(['items.product', 'items.stockUnit', 'items.fromBatch']);

        return $record;
    }
}
