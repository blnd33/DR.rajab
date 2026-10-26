<?php

namespace App\Filament\Admin\Resources\Suppliers\RelationManagers;

use App\Enums\PartyEntryType;
use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\LocalDateTimeColumn;
use App\Models\Customer;
use App\Models\PartyLedgerEntry;
use App\Services\Permissions\BranchScopeResolver;
use App\Support\Locale\Display;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

/**
 * A supplier's or customer's statement (append-only party ledger) with the running balance
 * per currency. Positive = owed (we owe the supplier / the customer owes us).
 */
class StatementRelationManager extends RelationManager
{
    protected static string $relationship = 'ledgerEntries';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Statement');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = ServiceAction::user();

        return $ownerRecord instanceof Customer
            ? app(BranchScopeResolver::class)->hasAny($user, 'sales.view_any')
            : app(BranchScopeResolver::class)->hasAny($user, 'purchases.view_any');
    }

    #[On('party-updated')]
    public function refreshRows(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['reference', 'user'])
                ->select('party_ledger_entries.*')
                ->selectRaw('SUM(amount) OVER (PARTITION BY currency ORDER BY occurred_at, id) AS running_balance'))
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('occurred_at')->orderByDesc('id'))
            ->columns([
                LocalDateTimeColumn::make('occurred_at')->label(__('When')),
                TextColumn::make('entry_type')->label(__('What'))->badge(),
                TextColumn::make('description')->label(__('Description'))->wrap()->placeholder('—'),
                TextColumn::make('amount')->label(__('Amount'))->alignEnd()->weight('semibold')
                    ->formatStateUsing(fn ($state, PartyLedgerEntry $r) => Display::money($state, $r->currency))
                    ->color(fn ($state) => (float) $state < 0 ? 'success' : 'warning'),
                TextColumn::make('running_balance')->label(__('Balance'))->alignEnd()
                    ->formatStateUsing(fn ($state, PartyLedgerEntry $r) => Display::money($state, $r->currency)),
                TextColumn::make('user.name')->label(__('By'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('currency')->label(__('Currency'))
                    ->options(fn () => PartyLedgerEntry::query()->where('party_type', $this->getOwnerRecord()->getMorphClass())->where('party_id', $this->getOwnerRecord()->getKey())->distinct()->pluck('currency', 'currency')->all()),
                SelectFilter::make('entry_type')->label(__('What'))->options(PartyEntryType::options()),
            ])
            ->emptyStateHeading(__('Nothing on the statement yet'));
    }
}
