<?php

namespace App\Filament\Admin\Resources\Drivers\RelationManagers;

use App\Filament\Admin\Support\ServiceAction;
use App\Models\Driver;
use App\Models\DriverTrip;
use App\Services\Agents\DriverService;
use App\Support\Locale\Display;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A driver's trips: arrival date, received date and what we paid (IQD, USD or EUR).
 * The amount is never changed: a wrong trip is voided with a reason and entered again.
 */
class DriverTripsRelationManager extends RelationManager
{
    protected static string $relationship = 'trips';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Trips');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    /**
     * @return list<mixed>
     */
    public static function tripFields(bool $withAmount = true): array
    {
        $today = fn () => now(config('orthotrack.timezone'))->toDateString();
        $currencies = config('orthotrack.currencies');

        return array_values(array_filter([
            DatePicker::make('arrived_on')->label(__('Date of arrival'))->native(false)->displayFormat('Y-m-d')->required()->default($today)->live(),
            DatePicker::make('received_on')->label(__('Date received'))->native(false)->displayFormat('Y-m-d')
                ->minDate(fn (Get $get) => $get('arrived_on'))->helperText(__('Empty if the goods are not received yet.')),
            $withAmount ? TextInput::make('amount')->label(__('What we paid'))->numeric()->minValue(0)->required() : null,
            $withAmount ? Select::make('currency')->label(__('Currency'))->native(false)->required()
                ->options(array_combine($currencies, $currencies))->default('IQD') : null,
            Textarea::make('notes')->label(__('Notes'))->rows(2)->columnSpanFull(),
        ]));
    }

    public static function addTripAction(): Action
    {
        return Action::make('add_trip')->label(__('Add a trip'))->icon(Heroicon::OutlinedPlus)
            ->visible(fn () => ServiceAction::user()->can('agents.manage'))
            ->schema(static::tripFields())
            ->action(function (array $data, Action $action, RelationManager $livewire) {
                /** @var Driver $driver */
                $driver = $livewire->getOwnerRecord();
                ServiceAction::run($action, fn ($u) => app(DriverService::class)->addTrip($driver, $data, $u), __('Trip saved.'));
            });
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('arrived_on', 'desc')
            ->recordTitleAttribute('arrived_on')
            ->description(function () {
                /** @var Driver $driver */
                $driver = $this->getOwnerRecord();
                $totals = app(DriverService::class)->paidTotals($driver);

                return $totals === [] ? null : __('Paid in total: :amounts', ['amounts' => implode(' · ', $totals)]);
            })
            ->columns([
                TextColumn::make('arrived_on')->label(__('Date of arrival'))->sortable()->formatStateUsing(fn ($state) => Display::date($state)),
                TextColumn::make('received_on')->label(__('Date received'))->sortable()->placeholder(__('Not received yet'))
                    ->formatStateUsing(fn ($state) => Display::date($state)),
                TextColumn::make('amount')->label(__('What we paid'))->alignEnd()->weight('semibold')
                    ->formatStateUsing(fn ($state, DriverTrip $record) => Display::money($state, $record->currency))
                    ->extraAttributes(fn (DriverTrip $record) => $record->isVoided() ? ['class' => 'line-through opacity-60'] : []),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->state(fn (DriverTrip $record) => match (true) {
                        $record->isVoided() => __('Voided'),
                        $record->received_on !== null => __('Goods received'),
                        default => __('On its way'),
                    })
                    ->color(fn (DriverTrip $record) => match (true) {
                        $record->isVoided() => 'gray',
                        $record->received_on !== null => 'success',
                        default => 'warning',
                    })
                    ->tooltip(fn (DriverTrip $record) => $record->void_reason),
                TextColumn::make('notes')->label(__('Notes'))->placeholder('—')->limit(50)->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('voided')->label(__('Voided'))->default(false)
                    ->queries(true: fn (Builder $q) => $q->whereNotNull('voided_at'), false: fn (Builder $q) => $q->whereNull('voided_at')),
            ])
            ->headerActions([static::addTripAction()])
            ->recordActions([
                Action::make('received')->label(__('Mark received'))->icon(Heroicon::OutlinedCheckCircle)->color('success')
                    ->visible(fn (DriverTrip $record) => ! $record->isVoided() && $record->received_on === null && ServiceAction::user()->can('agents.manage'))
                    ->schema([DatePicker::make('received_on')->label(__('Date received'))->native(false)->displayFormat('Y-m-d')->required()
                        ->default(fn () => now(config('orthotrack.timezone'))->toDateString())])
                    ->action(fn (DriverTrip $record, array $data, Action $action) => ServiceAction::run($action,
                        fn ($u) => app(DriverService::class)->markReceived($record, $data['received_on'], $u), __('Trip saved.'))),
                Action::make('edit')->label(__('Edit'))->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn (DriverTrip $record) => ! $record->isVoided() && ServiceAction::user()->can('agents.manage'))
                    ->fillForm(fn (DriverTrip $record) => [
                        'arrived_on' => $record->arrived_on->toDateString(),
                        'received_on' => $record->received_on?->toDateString(),
                        'notes' => $record->notes,
                    ])
                    ->schema(static::tripFields(withAmount: false))
                    ->modalDescription(__('To change the amount, void this trip and add it again.'))
                    ->action(fn (DriverTrip $record, array $data, Action $action) => ServiceAction::run($action,
                        fn ($u) => app(DriverService::class)->updateTrip($record, $data, $u), __('Trip saved.'))),
                Action::make('void')->label(__('Void'))->icon(Heroicon::OutlinedXCircle)->color('danger')
                    ->visible(fn (DriverTrip $record) => ! $record->isVoided() && ServiceAction::user()->can('agents.manage'))
                    ->requiresConfirmation()
                    ->modalDescription(__('The trip stays in the history, marked as voided, and no longer counts in the totals.'))
                    ->schema([Textarea::make('reason')->label(__('Reason'))->required()->rows(2)])
                    ->action(fn (DriverTrip $record, array $data, Action $action) => ServiceAction::run($action,
                        fn ($u) => app(DriverService::class)->voidTrip($record, (string) $data['reason'], $u), __('Trip voided.'))),
            ])
            ->emptyStateHeading(__('No trips yet'));
    }
}
