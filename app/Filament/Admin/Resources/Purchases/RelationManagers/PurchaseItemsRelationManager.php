<?php

namespace App\Filament\Admin\Resources\Purchases\RelationManagers;

use App\Filament\Admin\Tables\Columns\ProductColumn;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Support\Locale\Display;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

/**
 * Invoice lines with what arrived and the true unit cost (discount + landed costs).
 */
class PurchaseItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Lines');
    }

    #[On('purchase-updated')]
    public function refreshRows(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        /** @var Purchase $purchase */
        $purchase = $this->getOwnerRecord();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('product'))
            ->paginated(false)
            ->columns([
                ProductColumn::make('product'),
                TextColumn::make('expected_lot')->label(__('Lot'))->fontFamily(FontFamily::Mono)->extraAttributes(['dir' => 'ltr'])->placeholder('—')
                    ->description(fn (PurchaseItem $r) => $r->expected_expiry ? Display::date($r->expected_expiry) : null),
                TextColumn::make('quantity')->label(__('Ordered'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state)),
                TextColumn::make('quantity_received')->label(__('Received'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state))
                    ->color(fn (PurchaseItem $r) => $r->quantity_received >= $r->quantity ? 'success' : ($r->quantity_received > 0 ? 'warning' : 'gray')),
                TextColumn::make('unit_price')->label(__('Unit price'))->alignEnd()->formatStateUsing(fn ($state) => Display::money($state, $purchase->currency)),
                TextColumn::make('landed_cost_per_unit')->label(__('Landed / unit'))->alignEnd()->formatStateUsing(fn ($state) => Display::money($state, $purchase->currency))->toggleable(),
                TextColumn::make('true_unit_cost')->label(__('True unit cost'))->alignEnd()->weight('semibold')->formatStateUsing(fn ($state) => Display::money($state, $purchase->currency)),
                TextColumn::make('line_total')->label(__('Line total'))->alignEnd()->formatStateUsing(fn ($state) => Display::money($state, $purchase->currency)),
            ]);
    }
}
