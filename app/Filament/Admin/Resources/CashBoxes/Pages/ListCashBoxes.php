<?php

namespace App\Filament\Admin\Resources\CashBoxes\Pages;

use App\Filament\Admin\Resources\CashBoxes\CashBoxResource;
use App\Support\Locale\Display;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCashBoxes extends ListRecords
{
    protected static string $resource = CashBoxResource::class;

    public function getSubheading(): ?string
    {
        $totals = CashBoxResource::getEloquentQuery()->where('is_active', true)
            ->toBase()->groupBy('currency')->orderBy('currency')
            ->selectRaw('currency, SUM(current_balance) AS total')
            ->pluck('total', 'currency')
            ->map(fn ($total, $currency) => Display::money((string) $total, (string) $currency));

        return $totals->isEmpty() ? null : __('Total: :totals', ['totals' => $totals->implode(' · ')]);
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
