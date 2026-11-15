<?php

namespace App\Filament\Admin\Resources\SalesInvoices\Pages;

use App\Enums\InvoiceKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentTerms;
use App\Enums\SalesInvoiceStatus;
use App\Filament\Admin\Resources\CustomerPayments\CustomerPaymentResource;
use App\Filament\Admin\Resources\SalesInvoices\SalesInvoiceResource;
use App\Filament\Admin\Support\PrintAction;
use App\Filament\Admin\Support\RefreshesInfolist;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\SalesInvoice;
use App\Services\Sales\SalesService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewSalesInvoice extends ViewRecord
{
    use RefreshesInfolist;

    protected static string $resource = SalesInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        $user = ServiceAction::user();

        return [
            PrintAction::make('sales-invoice', fn () => $this->invoice()),
            SalesInvoiceResource::markPaidAction(fn () => $this->refreshInvoice())->record(fn () => $this->invoice()),
            SalesInvoiceResource::markNotPaidAction(fn () => $this->refreshInvoice())->record(fn () => $this->invoice()),
            Action::make('edit')
                ->label(__('Edit'))
                ->icon(Heroicon::OutlinedPencilSquare)
                ->color('gray')
                ->visible(fn () => $this->invoice()->isOpenForMarking() && $user->canInBranch('invoices.update', $this->invoice()->branch_id))
                ->url(fn () => SalesInvoiceResource::getUrl('edit', ['record' => $this->invoice()])),
            Action::make('collect')
                ->label(__('Receive payment'))
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('success')
                ->visible(fn () => $this->invoice()->payment_terms === PaymentTerms::Credit
                    && in_array($this->invoice()->status, [SalesInvoiceStatus::Issued, SalesInvoiceStatus::PartiallyPaid], true)
                    && $user->canInBranch('customer_payments.create', $this->invoice()->branch_id))
                ->schema(fn () => CustomerPaymentResource::receiveSchema(branchId: $this->invoice()->branch_id))
                ->fillForm(fn () => ['amount' => SalesInvoiceResource::open($this->invoice()), 'method' => PaymentMethod::Cash->value])
                ->action(function (array $data, Action $action) {
                    CustomerPaymentResource::receive($this->invoice()->customer, $data, $action);
                    $this->record = $this->invoice()->fresh();
                }),
            Action::make('void')
                ->label(fn () => $this->invoice()->kind === InvoiceKind::Surgery ? __('Void invoice') : __('Void sale'))
                ->icon(Heroicon::OutlinedXMark)
                ->color('danger')
                ->visible(fn () => $this->invoice()->status !== SalesInvoiceStatus::Voided && $user->canInBranch('sales.void', $this->invoice()->branch_id))
                ->modalDescription(fn () => $this->invoice()->kind === InvoiceKind::Surgery
                    ? __('The invoice is cancelled; any money taken for it leaves the box again. The surgery stays.')
                    : __('Items go back to the shelf and the money (or the customer\'s debt) is reversed.'))
                ->schema([Textarea::make('reason')->label(__('Reason'))->required()->rows(2)])
                ->action(function (array $data, Action $action) {
                    ServiceAction::run($action, fn ($u) => app(SalesService::class)->void($this->invoice(), $data['reason'], $u), __('Sale voided.'));
                    $this->record = $this->invoice()->fresh();
                    $this->dispatch('invoice-updated');
                }),
        ];
    }

    public function refreshInvoice(): void
    {
        $this->record = $this->invoice()->fresh();
        $this->dispatch('invoice-updated');
    }

    private function invoice(): SalesInvoice
    {
        /** @var SalesInvoice */
        return $this->getRecord();
    }
}
