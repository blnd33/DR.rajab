<?php

namespace App\Filament\Admin\Resources\Purchases\RelationManagers;

use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Models\LandedCost;
use App\Support\Locale\Display;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

/**
 * Shipping, customs, clearance, bank fees spread over the items (P4-9).
 */
class PurchaseLandedCostsRelationManager extends RelationManager
{
    protected static string $relationship = 'landedCosts';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Landed costs');
    }

    #[On('purchase-updated')]
    public function refreshRows(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['payee', 'creator']))
            ->paginated(false)
            ->columns([
                CodeColumn::make('number')->label(__('Number')),
                TextColumn::make('type')->label(__('Type'))->badge(),
                TextColumn::make('amount')->label(__('Amount'))->alignEnd()->weight('semibold')->formatStateUsing(fn ($state, LandedCost $r) => Display::money($state, $r->currency)),
                TextColumn::make('allocation_method')->label(__('Spread by'))->badge()->color('gray'),
                TextColumn::make('cogs_amount')->label(__('On items already sold'))->alignEnd()->toggleable()
                    ->formatStateUsing(fn ($state, LandedCost $r) => (float) $state > 0 ? Display::money($state, $r->currency) : '—'),
                TextColumn::make('payee.name')->label(__('Owed to'))->placeholder('—'),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('note')->label(__('Note'))->placeholder('—')->wrap()->toggleable(),
            ])
            ->emptyStateHeading(__('No landed costs'))
            ->emptyStateDescription(__('Add shipping or customs from "More".'));
    }
}
