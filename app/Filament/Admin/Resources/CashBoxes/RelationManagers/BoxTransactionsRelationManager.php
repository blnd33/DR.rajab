<?php

namespace App\Filament\Admin\Resources\CashBoxes\RelationManagers;

use App\Enums\MoneyTransactionType;
use App\Filament\Admin\Tables\Columns\LocalDateTimeColumn;
use App\Models\CashBox;
use App\Models\MoneyTransaction;
use App\Support\Locale\Display;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

/**
 * The box's money ledger (append-only, read-only).
 */
class BoxTransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Money movements');
    }

    #[On('box-updated')]
    public function refreshRows(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        /** @var CashBox $box */
        $box = $this->getOwnerRecord();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'reference']))
            ->defaultSort('id', 'desc')
            ->columns([
                LocalDateTimeColumn::make('occurred_at')->label(__('When')),
                TextColumn::make('type')->label(__('What'))->badge(),
                TextColumn::make('description')->label(__('Description'))->wrap()->placeholder('—')
                    ->description(fn (MoneyTransaction $r) => $r->reference !== null ? (string) ($r->reference->getAttribute('number') ?? '') : null),
                TextColumn::make('amount')->label(__('Amount'))->alignEnd()->weight('semibold')
                    ->formatStateUsing(fn ($state) => Display::money($state, $box->currency))
                    ->color(fn ($state) => (float) $state < 0 ? 'danger' : 'success'),
                TextColumn::make('balance_after')->label(__('Balance'))->alignEnd()->formatStateUsing(fn ($state) => Display::money($state, $box->currency)),
                TextColumn::make('amount_usd')->label(__('USD value'))->alignEnd()->formatStateUsing(fn ($state) => Display::money($state, 'USD'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('user.name')->label(__('By'))->toggleable(),
            ])
            ->filters([SelectFilter::make('type')->label(__('What'))->options(MoneyTransactionType::options())])
            ->emptyStateHeading(__('No money movements yet'));
    }
}
