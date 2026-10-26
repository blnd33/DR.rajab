<?php

namespace App\Filament\Admin\Resources\CurrencyExchanges\Pages;

use App\Filament\Admin\Resources\CurrencyExchanges\CurrencyExchangeResource;
use App\Models\CurrencyExchange;
use App\Support\Locale\Display;
use Filament\Resources\Pages\ManageRecords;

class ManageCurrencyExchanges extends ManageRecords
{
    protected static string $resource = CurrencyExchangeResource::class;

    public function getSubheading(): ?string
    {
        $total = (string) CurrencyExchangeResource::getEloquentQuery()->whereNull('voided_at')->sum('gain_loss_usd');

        return CurrencyExchange::query()->exists() ? __('Gain / loss so far: :amount', ['amount' => Display::money($total, 'USD')]) : null;
    }
}
