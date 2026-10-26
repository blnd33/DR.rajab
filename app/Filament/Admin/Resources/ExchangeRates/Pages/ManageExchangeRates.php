<?php

namespace App\Filament\Admin\Resources\ExchangeRates\Pages;

use App\Filament\Admin\Resources\ExchangeRates\ExchangeRateResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Services\Money\FxService;
use App\Support\Locale\Display;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageExchangeRates extends ManageRecords
{
    protected static string $resource = ExchangeRateResource::class;

    public function getSubheading(): ?string
    {
        $current = app(FxService::class)->current();

        return $current === null
            ? __('No rates yet — money screens need both rates.')
            : __('Now: 1 USD = :iqd IQD · 1 EUR = :usd USD', ['iqd' => Display::number($current->usd_iqd, 2), 'usd' => Display::number($current->eur_usd, 4)]);
    }

    protected function getHeaderActions(): array
    {
        $user = ServiceAction::user();
        $set = fn (string $base, string $quote, string $permission) => Action::make("set_{$base}_{$quote}")
            ->label(__('Set :base → :quote', ['base' => $base, 'quote' => $quote]))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->visible(fn () => $user->can($permission))
            ->schema([
                TextInput::make('rate')->label(__('1 :base = … :quote', ['base' => $base, 'quote' => $quote]))->numeric()->minValue(0)->required()
                    ->extraInputAttributes(['dir' => 'ltr']),
                TextInput::make('note')->label(__('Note'))->maxLength(191),
            ])
            ->action(fn (array $data, Action $action) => ServiceAction::run($action, fn ($u) => app(FxService::class)->setRate($base, $quote, (string) $data['rate'], $u, $data['note'] ?? null), __('Rate saved.')));

        return [
            $set('USD', 'IQD', 'exchange_rates.set_usd_iqd'),
            $set('EUR', 'USD', 'exchange_rates.set_eur_usd'),
            Action::make('ecb')
                ->label(__('Get EUR → USD from the ECB'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible(fn () => $user->can('exchange_rates.set_eur_usd'))
                ->action(function () {
                    $rate = app(FxService::class)->fetchEcb();

                    $rate === null
                        ? Notification::make()->title(__('No new rate from the ECB right now.'))->warning()->send()
                        : Notification::make()->title(__('ECB rate :rate added — confirm it in the list.', ['rate' => Display::number($rate->rate, 4)]))->success()->send();
                }),
        ];
    }
}
