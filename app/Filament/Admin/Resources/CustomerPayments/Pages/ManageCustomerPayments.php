<?php

namespace App\Filament\Admin\Resources\CustomerPayments\Pages;

use App\Filament\Admin\Resources\CustomerPayments\CustomerPaymentResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Services\Permissions\BranchScopeResolver;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageCustomerPayments extends ManageRecords
{
    protected static string $resource = CustomerPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('receive')
                ->label(__('Receive a payment'))
                ->icon(Heroicon::OutlinedBanknotes)
                ->visible(fn () => app(BranchScopeResolver::class)->hasAny(ServiceAction::user(), 'customer_payments.create'))
                ->schema(CustomerPaymentResource::receiveSchema(pickCustomer: true))
                ->action(fn (array $data, Action $action) => CustomerPaymentResource::receive(null, $data, $action)),
        ];
    }
}
