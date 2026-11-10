<?php

namespace App\Filament\Admin\Actions;

use App\Filament\Admin\Support\ServiceAction;
use App\Models\Currency;
use App\Services\Catalog\ProductService;
use App\Support\Locale\Display;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

/**
 * "Set price" on the products list: one selling / purchase price for every selected product,
 * or a percentage up or down. Use "Select all" in the list to change the whole catalogue.
 */
final class SetPricesAction
{
    public static function make(): BulkAction
    {
        return BulkAction::make('setPrices')
            ->label(__('Set price'))
            ->icon(Heroicon::OutlinedCurrencyDollar)
            ->visible(fn () => ServiceAction::user()->can('products.manage'))
            ->modalHeading(__('Set price'))
            ->modalDescription(fn (Collection $records) => trans_choice(':count product selected.|:count products selected.', $records->count(), ['count' => Display::number($records->count())]))
            ->modalSubmitActionLabel(__('Change the prices'))
            ->schema([
                ToggleButtons::make('price')->label(__('Which price'))->inline()->required()->default('selling')
                    ->options(['selling' => __('Selling price'), 'purchase' => __('Purchase price'), 'both' => __('Both')]),
                ToggleButtons::make('mode')->label(__('How'))->inline()->required()->default('set')->live()
                    ->options(['set' => __('One price for all'), 'percent' => __('Up or down by %')]),
                TextInput::make('amount')->label(__('Price'))->numeric()->minValue(0)->required()
                    ->extraInputAttributes(['dir' => 'ltr'])
                    ->visible(fn (Get $get) => $get('mode') === 'set'),
                ToggleButtons::make('currency')->label(__('Currency'))->inline()->required()->default('USD')
                    ->options(fn () => collect(Currency::options())->mapWithKeys(fn ($label, $code) => [$code => $code])->all())
                    ->visible(fn (Get $get) => $get('mode') === 'set'),
                Toggle::make('only_empty')->label(__('Only products that have no price yet'))->default(false)
                    ->visible(fn (Get $get) => $get('mode') === 'set'),
                TextInput::make('percent')->label(__('Percent'))->numeric()->required()->suffix('%')
                    ->extraInputAttributes(['dir' => 'ltr'])
                    ->helperText(__('10 = 10 % more, -5 = 5 % less. Products without that price are skipped; the currency stays.'))
                    ->visible(fn (Get $get) => $get('mode') === 'percent'),
            ])
            ->deselectRecordsAfterCompletion()
            ->action(
                function (Collection $records, array $data, BulkAction $action) {
                    $ids = array_map('intval', $records->modelKeys());
                    $result = ServiceAction::run($action, fn ($u) => app(ProductService::class)->setPrices($ids, $data, $u));

                    if ($result === null) {
                        return;
                    }

                    Notification::make()
                        ->title(trans_choice(':count price changed.|:count prices changed.', $result['changed'], ['count' => Display::number($result['changed'])]))
                        ->body($result['skipped'] > 0 ? trans_choice(':count product was left as it was.|:count products were left as they were.', $result['skipped'], ['count' => Display::number($result['skipped'])]) : null)
                        ->success()
                        ->send();
                });
    }
}
