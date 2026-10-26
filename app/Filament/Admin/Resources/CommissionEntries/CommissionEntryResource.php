<?php

namespace App\Filament\Admin\Resources\CommissionEntries;

use App\Enums\CommissionStatus;
use App\Filament\Admin\Resources\CommissionEntries\Pages\ManageCommissionEntries;
use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Filament\Admin\Tables\Columns\LocalDateTimeColumn;
use App\Models\CashBox;
use App\Models\CommissionEntry;
use App\Models\User;
use App\Services\Expenses\CommissionService;
use App\Services\Money\CashBoxService;
use App\Support\Locale\Display;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Commissions earned on completed surgeries: approve, then pay from a box.
 */
class CommissionEntryResource extends Resource
{
    protected static ?string $model = CommissionEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 70;

    /**
     * Behind the features.commissions switch, for super admins too.
     */
    public static function canAccess(): bool
    {
        return app(CommissionService::class)->enabled() && parent::canAccess();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Finance');
    }

    public static function getModelLabel(): string
    {
        return __('Commission');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Commissions');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['doctor', 'surgery', 'rule']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('doctor.name')->label(__('Doctor')),
                CodeColumn::make('surgery.number')->label(__('Surgery')),
                TextColumn::make('amount')->label(__('Amount'))->alignEnd()->weight('semibold')->formatStateUsing(fn ($state, CommissionEntry $r) => Display::money($state, $r->currency)),
                TextColumn::make('status')->label(__('Status'))->badge(),
                LocalDateTimeColumn::make('created_at')->label(__('Earned')),
                LocalDateTimeColumn::make('approved_at')->label(__('Approved'))->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(CommissionStatus::options())->default(CommissionStatus::Accrued->value),
                SelectFilter::make('doctor_id')->label(__('Doctor'))->options(fn () => User::doctorOptions()),
            ])
            ->recordActions([
                Action::make('approve')->label(__('Approve'))->icon(Heroicon::OutlinedCheck)->color('success')
                    ->visible(fn (CommissionEntry $r) => $r->status === CommissionStatus::Accrued)
                    ->action(fn (CommissionEntry $record, Action $action) => ServiceAction::run($action, fn ($u) => app(CommissionService::class)->approve($record, $u), __('Approved.'))),
            ])
            ->toolbarActions([
                BulkAction::make('pay')->label(__('Pay selected'))->icon(Heroicon::OutlinedBanknotes)->color('success')
                    ->schema([Select::make('cash_box_id')->label(__('Pay from'))->required()->native(false)
                        ->options(fn () => app(CashBoxService::class)->options(ServiceAction::user(), 'cash_boxes.view_any'))])
                    ->action(function (Collection $records, array $data, BulkAction $action) {
                        $box = CashBox::query()->findOrFail($data['cash_box_id']);
                        $total = ServiceAction::run(null, fn ($u) => app(CommissionService::class)->pay($records->modelKeys(), $box, $u));

                        if ($total !== null) {
                            Notification::make()->title(__('Paid :amount.', ['amount' => Display::money($total, $box->currency)]))->success()->send();
                        }
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->emptyStateHeading(__('No commissions'))
            ->emptyStateDescription(__('Commissions are earned when a surgery is completed.'));
    }

    public static function getPages(): array
    {
        return ['index' => ManageCommissionEntries::route('/')];
    }
}
