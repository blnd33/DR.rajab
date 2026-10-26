<?php

namespace App\Filament\Admin\Resources\ExchangeRates;

use App\Enums\RateSource;
use App\Enums\RateStatus;
use App\Filament\Admin\Resources\ExchangeRates\Pages\ManageExchangeRates;
use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\LocalDateTimeColumn;
use App\Models\ExchangeRate;
use App\Services\Money\FxService;
use App\Support\Locale\Display;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Exchange rates with full history (brief §3.3, P4-2): who set what, when, from where.
 */
class ExchangeRateResource extends Resource
{
    protected static ?string $model = ExchangeRate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return __('Finance');
    }

    public static function getModelLabel(): string
    {
        return __('Exchange rate');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Exchange rates');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = ExchangeRate::query()->where('status', RateStatus::Suggested)->count();

        return $count > 0 ? Display::number($count) : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['setter', 'confirmer']))
            ->defaultSort('effective_at', 'desc')
            ->columns([
                TextColumn::make('pair')->label(__('Pair'))->state(fn (ExchangeRate $r) => "{$r->base_currency} → {$r->quote_currency}")->fontFamily('mono')->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('rate')->label(__('Rate'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state, 4))->weight('semibold'),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('source')->label(__('Source'))->badge()->color('gray'),
                LocalDateTimeColumn::make('effective_at')->label(__('From')),
                TextColumn::make('setter.name')->label(__('Set by'))->placeholder('—'),
                TextColumn::make('confirmer.name')->label(__('Confirmed by'))->placeholder('—')->toggleable(),
                TextColumn::make('note')->label(__('Note'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(RateStatus::options()),
                SelectFilter::make('source')->label(__('Source'))->options(RateSource::options()),
                SelectFilter::make('base_currency')->label(__('Pair'))->options(['USD' => 'USD → IQD', 'EUR' => 'EUR → USD']),
            ])
            ->recordActions([
                Action::make('confirm')->label(__('Confirm'))->icon(Heroicon::OutlinedCheck)->color('success')
                    ->visible(fn (ExchangeRate $r) => $r->status === RateStatus::Suggested)
                    ->requiresConfirmation()
                    ->action(fn (ExchangeRate $record, Action $action) => ServiceAction::run($action, fn ($u) => app(FxService::class)->confirm($record, $u), __('Rate confirmed. New documents use it from now on.'))),
                Action::make('reject')->label(__('Reject'))->icon(Heroicon::OutlinedXMark)->color('gray')
                    ->visible(fn (ExchangeRate $r) => $r->status === RateStatus::Suggested)
                    ->requiresConfirmation()
                    ->action(fn (ExchangeRate $record, Action $action) => ServiceAction::run($action, fn ($u) => app(FxService::class)->reject($record, $u), __('Suggestion rejected.'))),
            ])
            ->emptyStateHeading(__('No exchange rates yet'))
            ->emptyStateDescription(__('Set USD → IQD and EUR → USD before recording money.'));
    }

    public static function getPages(): array
    {
        return ['index' => ManageExchangeRates::route('/')];
    }
}
