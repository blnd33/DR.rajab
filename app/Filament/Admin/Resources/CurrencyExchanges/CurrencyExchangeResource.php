<?php

namespace App\Filament\Admin\Resources\CurrencyExchanges;

use App\Filament\Admin\Resources\CurrencyExchanges\Pages\ManageCurrencyExchanges;
use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Filament\Admin\Tables\Columns\LocalDateTimeColumn;
use App\Models\CurrencyExchange;
use App\Services\Money\CurrencyExchangeService;
use App\Support\Locale\Display;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Currency exchanges of own money with their gain / loss over time (Module 20).
 */
class CurrencyExchangeResource extends Resource
{
    protected static ?string $model = CurrencyExchange::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?int $navigationSort = 35;

    public static function getNavigationGroup(): ?string
    {
        return __('Finance');
    }

    public static function getModelLabel(): string
    {
        return __('Currency exchange');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Currency exchanges');
    }

    public static function getEloquentQuery(): Builder
    {
        return ServiceAction::scoped(parent::getEloquentQuery(), 'cash_boxes.view_any', ['branch_id']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['fromBox.branch', 'toBox.branch', 'performer']))
            ->defaultSort('performed_at', 'desc')
            ->columns([
                CodeColumn::make('number')->label(__('Number'))->searchable(),
                TextColumn::make('given')->label(__('Given'))->alignEnd()->state(fn (CurrencyExchange $r) => Display::money($r->from_amount, $r->from_currency))->description(fn (CurrencyExchange $r) => $r->fromBox->label()),
                TextColumn::make('received')->label(__('Received'))->alignEnd()->state(fn (CurrencyExchange $r) => Display::money($r->to_amount, $r->to_currency))->description(fn (CurrencyExchange $r) => $r->toBox->label()),
                TextColumn::make('rate')->label(__('Rate'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state, 4)),
                TextColumn::make('gain_loss_usd')->label(__('Gain / loss'))->alignEnd()
                    ->formatStateUsing(fn ($state) => Display::money($state, 'USD'))
                    ->color(fn ($state) => (float) $state < 0 ? 'danger' : 'success'),
                LocalDateTimeColumn::make('performed_at')->label(__('When')),
                TextColumn::make('void_reason')->label(__('Voided'))->placeholder('—')->color('danger'),
            ])
            ->filters([TernaryFilter::make('voided_at')->label(__('Voided'))->nullable()])
            ->recordActions([
                Action::make('void')->label(__('Void'))->icon(Heroicon::OutlinedXMark)->color('danger')
                    ->visible(fn (CurrencyExchange $r) => $r->voided_at === null && ServiceAction::user()->canInBranch('currency_exchange.create', $r->branch_id))
                    ->schema([Textarea::make('reason')->label(__('Reason'))->required()->rows(2)])
                    ->action(fn (CurrencyExchange $record, array $data, Action $action) => ServiceAction::run($action, fn ($u) => app(CurrencyExchangeService::class)->void($record, $data['reason'], $u), __('Exchange voided.'))),
            ])
            ->emptyStateHeading(__('No currency exchanges yet'))
            ->emptyStateDescription(__('Exchange money from a cash box page.'));
    }

    public static function getPages(): array
    {
        return ['index' => ManageCurrencyExchanges::route('/')];
    }
}
