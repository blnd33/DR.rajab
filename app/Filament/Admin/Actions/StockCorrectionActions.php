<?php

namespace App\Filament\Admin\Actions;

use App\Enums\StockStatus;
use App\Exceptions\Domain\StockRuleViolation;
use App\Models\BranchLocation;
use App\Models\Currency;
use App\Models\StockBatch;
use App\Models\StockUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Stock\StockMovementService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Corrections of units and lots — always with a reason, always on the stock ledger.
 */
final class StockCorrectionActions
{
    public static function correctUnit(): Action
    {
        return Action::make('correct')
            ->label(__('Correct details'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('warning')
            ->visible(fn (StockUnit $record) => Auth::user()?->can('correct', $record))
            ->modalHeading(fn (StockUnit $record) => __('Correct :serial', ['serial' => $record->serial]))
            ->modalDescription(__('Fix what was recorded wrongly. The old and new values are kept on the stock ledger.'))
            ->fillForm(fn (StockUnit $record) => [
                'lot' => $record->lot,
                'expiry_date' => $record->expiry_date?->toDateString(),
                'production_date' => $record->production_date?->toDateString(),
                'base_unit_cost' => $record->base_unit_cost,
                'cost_currency' => $record->cost_currency,
                'supplier_id' => $record->supplier_id,
                'branch_location_id' => $record->branch_location_id,
            ])
            ->schema(fn (StockUnit $record) => self::fields($record->branch_id))
            ->action(function (StockUnit $record, array $data, Action $action) {
                self::run($action, fn (User $user) => app(StockMovementService::class)->correctUnit($record, $data, $data['reason'], $user));
            });
    }

    public static function correctBatch(): Action
    {
        return Action::make('correct')
            ->label(__('Correct details'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('warning')
            ->visible(fn (StockBatch $record) => Auth::user()?->can('correct', $record))
            ->modalHeading(fn (StockBatch $record) => __('Correct lot :lot', ['lot' => $record->lot ?? '—']))
            ->modalDescription(__('Fix what was recorded wrongly. The quantity moves with the change; old and new values are kept on the stock ledger.'))
            ->fillForm(fn (StockBatch $record) => [
                'lot' => $record->lot,
                'expiry_date' => $record->expiry_date?->toDateString(),
                'production_date' => $record->production_date?->toDateString(),
                'base_unit_cost' => $record->base_unit_cost,
                'cost_currency' => $record->cost_currency,
                'supplier_id' => $record->supplier_id,
                'branch_location_id' => $record->branch_location_id,
            ])
            ->schema(fn (StockBatch $record) => self::fields($record->branch_id))
            ->action(function (StockBatch $record, array $data, Action $action) {
                self::run($action, fn (User $user) => app(StockMovementService::class)->correctBatch($record, $data, $data['reason'], $user));
            });
    }

    public static function overrideStatus(): Action
    {
        return Action::make('overrideStatus')
            ->label(__('Correct status'))
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->color('danger')
            ->visible(fn (StockUnit $record) => Auth::user()?->can('overrideStatus', $record))
            ->modalHeading(fn (StockUnit $record) => __('Correct the status of :serial', ['serial' => $record->serial]))
            ->modalDescription(__('Only for a status recorded wrongly. Damaged, lost or expired items go through a write-off.'))
            ->schema([
                Select::make('status')
                    ->label(__('New status'))
                    ->options(StockStatus::options())
                    ->required()
                    ->native(false),
                Textarea::make('reason')->label(__('Reason'))->required()->rows(2),
            ])
            ->action(function (StockUnit $record, array $data, Action $action) {
                self::run($action, fn (User $user) => app(StockMovementService::class)->overrideStatus($record, StockStatus::from($data['status']), $data['reason'], $user));
            });
    }

    /**
     * @return list<Component|Field>
     */
    private static function fields(int $branchId): array
    {
        return [
            TextInput::make('lot')->label(__('Lot'))->maxLength(64)->extraInputAttributes(['dir' => 'ltr', 'class' => 'ot-code']),
            DatePicker::make('expiry_date')->label(__('Expiry date'))->native(false)->displayFormat('Y-m-d'),
            DatePicker::make('production_date')->label(__('Production date'))->native(false)->displayFormat('Y-m-d'),
            TextInput::make('base_unit_cost')->label(__('Purchase price (per unit)'))->numeric()->minValue(0)->extraInputAttributes(['dir' => 'ltr']),
            Select::make('cost_currency')->label(__('Currency'))->options(fn () => Currency::options())->required()->native(false),
            Select::make('supplier_id')->label(__('Supplier'))->options(fn () => Supplier::options())->searchable()->native(false),
            Select::make('branch_location_id')
                ->label(__('Shelf'))
                ->options(fn () => BranchLocation::query()->active()->where('branch_id', $branchId)->orderBy('code')->get()->mapWithKeys(fn (BranchLocation $l) => [$l->id => $l->label()]))
                ->native(false),
            Textarea::make('reason')->label(__('Reason'))->required()->rows(2),
        ];
    }

    private static function run(Action $action, \Closure $callback): void
    {
        /** @var User $user */
        $user = Auth::user();

        try {
            $movement = $callback($user);
        } catch (StockRuleViolation $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            $action->halt();

            return;
        }

        Notification::make()
            ->title($movement === null ? __('Nothing changed.') : __('Saved. The change is on the stock ledger.'))
            ->success()
            ->send();
    }
}
