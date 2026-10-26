<?php

namespace App\Filament\Admin\Resources\SupplierReturns\Pages;

use App\Enums\SupplierReturnStatus;
use App\Filament\Admin\Resources\SupplierReturns\SupplierReturnResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\SupplierReturn;
use App\Services\Movement\ReturnService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewSupplierReturn extends ViewRecord
{
    protected static string $resource = SupplierReturnResource::class;

    protected function getHeaderActions(): array
    {
        $isDraft = fn () => $this->supplierReturn()->status === SupplierReturnStatus::Draft
            && ServiceAction::user()->canInBranch('returns.supplier', $this->supplierReturn()->branch_id);

        return [
            Action::make('send')
                ->label(__('Send to supplier'))
                ->icon(Heroicon::OutlinedTruck)
                ->visible($isDraft)
                ->requiresConfirmation()
                ->modalDescription(__('The items leave stock as "returned to supplier". The credit note is booked with purchases.'))
                ->action(function (Action $action) {
                    ServiceAction::run($action, fn ($u) => app(ReturnService::class)->sendSupplierReturn($this->supplierReturn(), $u), __('Return sent.'));
                    $this->record = $this->supplierReturn()->fresh();
                }),
            Action::make('cancel')
                ->label(__('Cancel'))
                ->icon(Heroicon::OutlinedXMark)
                ->color('danger')
                ->visible($isDraft)
                ->schema([Textarea::make('reason')->label(__('Reason'))->required()->rows(2)])
                ->action(function (array $data, Action $action) {
                    ServiceAction::run($action, fn ($u) => app(ReturnService::class)->cancelSupplierReturn($this->supplierReturn(), $data['reason'], $u), __('Return cancelled.'));
                    $this->record = $this->supplierReturn()->fresh();
                }),
        ];
    }

    private function supplierReturn(): SupplierReturn
    {
        /** @var SupplierReturn */
        return $this->getRecord();
    }
}
