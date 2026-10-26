<?php

namespace App\Filament\Admin\Resources\Suppliers\Pages;

use App\Filament\Admin\Resources\SupplierPayments\SupplierPaymentResource;
use App\Filament\Admin\Resources\Suppliers\SupplierResource;
use App\Filament\Admin\Support\RefreshesInfolist;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\Supplier;
use App\Services\Purchasing\SupplierPaymentService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;

/**
 * A supplier with what we owe them (per currency), their invoices and the statement (Module 7).
 */
class ViewSupplier extends ViewRecord
{
    use RefreshesInfolist;

    protected static string $resource = SupplierResource::class;

    public function getTitle(): string
    {
        return $this->supplier()->name;
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(['default' => 2, 'md' => 4])->schema([
                TextEntry::make('code')->label(__('Code'))->fontFamily(FontFamily::Mono)->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('owed')->label(__('We owe'))->weight('bold')->state(fn (Supplier $r) => app(SupplierPaymentService::class)->owedText($r)),
                TextEntry::make('default_currency')->label(__('Default currency'))->badge()->color('gray'),
                TextEntry::make('payment_terms_days')->label(__('Payment terms (days)'))->placeholder('—'),
                TextEntry::make('contact_person')->label(__('Contact person'))->placeholder('—'),
                TextEntry::make('phone')->label(__('Phone'))->fontFamily(FontFamily::Mono)->extraAttributes(['dir' => 'ltr'])->placeholder('—'),
                TextEntry::make('email')->label(__('Email'))->placeholder('—'),
                TextEntry::make('country')->label(__('Country'))->placeholder('—'),
            ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pay')
                ->label(__('Pay'))
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('success')
                ->visible(fn () => ServiceAction::user()->can('supplier_payments.create'))
                ->schema(SupplierPaymentResource::paySchema())
                ->action(function (array $data, Action $action) {
                    SupplierPaymentResource::pay($this->supplier(), $data, $action);
                    $this->record = $this->supplier()->fresh();
                    $this->dispatch('party-updated');
                }),
            EditAction::make()->schema(fn (Schema $schema) => SupplierResource::form($schema)),
        ];
    }

    private function supplier(): Supplier
    {
        /** @var Supplier */
        return $this->getRecord();
    }
}
