<?php

namespace App\Filament\Admin\Resources\SupplierReturns\Pages;

use App\Enums\SupplierReturnStatus;
use App\Filament\Admin\Resources\SupplierReturns\SupplierReturnResource;
use App\Filament\Admin\Support\RefreshesInfolist;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\SupplierReturn;
use App\Services\Movement\ReturnService;
use App\Services\Purchasing\CreditNoteService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewSupplierReturn extends ViewRecord
{
    use RefreshesInfolist;

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
                ->modalDescription(__('The items leave stock as "returned to supplier". Record the supplier\'s credit afterwards.'))
                ->action(function (Action $action) {
                    ServiceAction::run($action, fn ($u) => app(ReturnService::class)->sendSupplierReturn($this->supplierReturn(), $u), __('Return sent.'));
                    $this->record = $this->supplierReturn()->fresh();
                }),
            Action::make('credit')
                ->label(__('Record supplier credit'))
                ->icon(Heroicon::OutlinedReceiptRefund)
                ->color('success')
                ->visible(fn () => $this->supplierReturn()->status === SupplierReturnStatus::Sent
                    && ServiceAction::user()->canInBranch('returns.supplier', $this->supplierReturn()->branch_id)
                    && ServiceAction::user()->can('supplier_payments.create'))
                ->modalDescription(__('The credit lowers what you owe the supplier.'))
                ->fillForm(fn () => ['amount' => $this->supplierReturn()->total_credit])
                ->schema([
                    TextInput::make('amount')->label(fn () => __('Amount (:currency)', ['currency' => $this->supplierReturn()->currency ?? '…']))->numeric()->minValue(0)->required(),
                    TextInput::make('reference')->label(__('Supplier credit note no.'))->maxLength(64)->extraInputAttributes(['dir' => 'ltr', 'class' => 'ot-code']),
                    Textarea::make('note')->label(__('Note'))->rows(2),
                ])
                ->action(function (array $data, Action $action) {
                    ServiceAction::run($action, fn ($u) => app(CreditNoteService::class)->fromSupplierReturn($this->supplierReturn(), $u, (string) $data['amount'], $data['reference'] ?? null, $data['note'] ?? null), __('Credit recorded.'));
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
