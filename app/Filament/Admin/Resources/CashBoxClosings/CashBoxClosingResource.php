<?php

namespace App\Filament\Admin\Resources\CashBoxClosings;

use App\Enums\ClosingStatus;
use App\Filament\Admin\Resources\CashBoxClosings\Pages\ManageCashBoxClosings;
use App\Filament\Admin\Support\PrintAction;
use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\LocalDateTimeColumn;
use App\Models\CashBox;
use App\Models\CashBoxClosing;
use App\Services\Money\CashClosingService;
use App\Support\Locale\Display;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daily cash closings (Module 19, P4-5): count, explain the difference, review.
 */
class CashBoxClosingResource extends Resource
{
    protected static ?string $model = CashBoxClosing::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?int $navigationSort = 25;

    public static function getNavigationGroup(): ?string
    {
        return __('Finance');
    }

    public static function getModelLabel(): string
    {
        return __('Daily closing');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Daily closings');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('cash_box_id', ServiceAction::scoped(CashBox::query(), 'cash_boxes.view_any', ['branch_id'])->select('id'));
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', ClosingStatus::Submitted)->where('difference', '<>', 0)->count();

        return $count > 0 ? Display::number($count) : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['cashBox.branch', 'counter', 'reviewer']))
            ->defaultSort('business_date', 'desc')
            ->columns([
                TextColumn::make('business_date')->label(__('Day'))->sortable()->formatStateUsing(fn ($state) => Display::date($state)),
                TextColumn::make('box')->label(__('Box'))->state(fn (CashBoxClosing $r) => $r->cashBox->label()),
                TextColumn::make('expected_balance')->label(__('Expected'))->alignEnd()->formatStateUsing(fn ($state, CashBoxClosing $r) => Display::money($state, $r->cashBox->currency)),
                TextColumn::make('counted_balance')->label(__('Counted'))->alignEnd()->placeholder('—')->formatStateUsing(fn ($state, CashBoxClosing $r) => Display::money($state, $r->cashBox->currency)),
                TextColumn::make('difference')->label(__('Difference'))->alignEnd()
                    ->formatStateUsing(fn ($state, CashBoxClosing $r) => Display::money($state, $r->cashBox->currency))
                    ->color(fn ($state) => (float) $state !== 0.0 ? 'danger' : 'success')
                    ->description(fn (CashBoxClosing $r) => $r->difference_reason),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('counter.name')->label(__('Counted by'))->placeholder('—')->toggleable(),
                LocalDateTimeColumn::make('submitted_at')->label(__('Submitted'))->placeholder('—')->toggleable(),
            ])
            ->filters([SelectFilter::make('status')->label(__('Status'))->options(ClosingStatus::options())])
            ->recordActions([
                PrintAction::make('cash-closing')->iconButton()->visible(fn (CashBoxClosing $r) => $r->status !== ClosingStatus::Draft),
                Action::make('count')
                    ->label(__('Count and submit'))
                    ->icon(Heroicon::OutlinedCalculator)
                    ->visible(fn (CashBoxClosing $r) => $r->status === ClosingStatus::Draft && ServiceAction::user()->canInBranch('cash_boxes.close_day', $r->cashBox->branch_id))
                    ->modalDescription(fn (CashBoxClosing $r) => __('Expected now: :amount', ['amount' => Display::money($r->cashBox->current_balance, $r->cashBox->currency)]))
                    ->schema([
                        TextInput::make('counted')->label(__('Counted in the drawer'))->numeric()->minValue(0)->required(),
                        KeyValue::make('denominations')->label(__('Notes counted (optional)'))->keyLabel(__('Note'))->valueLabel(__('How many')),
                        Textarea::make('reason')->label(__('Reason for a difference'))->rows(2),
                    ])
                    ->action(fn (CashBoxClosing $record, array $data, Action $action) => ServiceAction::run($action,
                        fn ($u) => app(CashClosingService::class)->submit($record, (string) $data['counted'], $u, $data['reason'] ?? null, array_map('intval', array_filter((array) ($data['denominations'] ?? []), 'is_numeric')) ?: null),
                        __('Closing submitted and locked.'))),
                Action::make('review')
                    ->label(__('Mark reviewed'))
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->color('success')
                    ->visible(fn (CashBoxClosing $r) => $r->status === ClosingStatus::Submitted && ServiceAction::user()->canInBranch('cash_boxes.review_closing', $r->cashBox->branch_id))
                    ->schema([Textarea::make('note')->label(__('Note'))->rows(2)])
                    ->action(fn (CashBoxClosing $record, array $data, Action $action) => ServiceAction::run($action, fn ($u) => app(CashClosingService::class)->review($record, $u, $data['note'] ?? null), __('Closing reviewed.'))),
            ])
            ->emptyStateHeading(__('No closings yet'))
            ->emptyStateDescription(__('Open a cash box and press "Close the day".'));
    }

    public static function getPages(): array
    {
        return ['index' => ManageCashBoxClosings::route('/')];
    }
}
