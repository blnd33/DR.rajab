<?php

namespace App\Filament\Admin\Resources\SalesInvoices\Pages;

use App\Enums\PaymentTerms;
use App\Enums\SalesInvoiceStatus;
use App\Filament\Admin\Resources\SalesInvoices\SalesInvoiceResource;
use App\Support\Locale\Display;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/**
 * Invoices: not paid (money not in our hands yet), paid, and all.
 */
class ListSalesInvoices extends ListRecords
{
    protected static string $resource = SalesInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('New sale'))];
    }

    public function getSubheading(): ?string
    {
        $totals = SalesInvoiceResource::getEloquentQuery()
            ->where('status', SalesInvoiceStatus::Issued)->where('payment_terms', '<>', PaymentTerms::Credit)
            ->groupBy('currency')->selectRaw('currency, SUM(total) AS total')->orderBy('currency')
            ->toBase()->get();

        if ($totals->isEmpty()) {
            return __('Everything is paid.');
        }

        return __('Not paid yet: :amounts', ['amounts' => $totals->map(fn ($row) => Display::money((string) $row->total, (string) $row->currency))->implode(' · ')]);
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $unpaid = fn (Builder $query) => $query->where('status', SalesInvoiceStatus::Issued)->where('payment_terms', '<>', PaymentTerms::Credit);

        return [
            'unpaid' => Tab::make(__('Not paid'))
                ->modifyQueryUsing($unpaid)
                ->badge(fn () => Display::number($unpaid(SalesInvoiceResource::getEloquentQuery())->count())),
            'paid' => Tab::make(__('Paid'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', SalesInvoiceStatus::Paid)),
            'all' => Tab::make(__('All')),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'unpaid';
    }
}
