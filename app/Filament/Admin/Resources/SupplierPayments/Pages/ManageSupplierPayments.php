<?php

namespace App\Filament\Admin\Resources\SupplierPayments\Pages;

use App\Filament\Admin\Resources\SupplierPayments\SupplierPaymentResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\Supplier;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageSupplierPayments extends ManageRecords
{
    protected static string $resource = SupplierPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pay')
                ->label(__('Pay a supplier'))
                ->icon(Heroicon::OutlinedBanknotes)
                ->visible(fn () => ServiceAction::user()->can('supplier_payments.create'))
                ->schema(SupplierPaymentResource::paySchema(pickSupplier: true))
                ->action(fn (array $data, Action $action) => SupplierPaymentResource::pay(Supplier::query()->findOrFail($data['supplier_id']), $data, $action)),
        ];
    }
}
