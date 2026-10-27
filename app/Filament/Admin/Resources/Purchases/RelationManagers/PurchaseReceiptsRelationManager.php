<?php

namespace App\Filament\Admin\Resources\Purchases\RelationManagers;

use App\Filament\Admin\Support\PrintAction;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Filament\Admin\Tables\Columns\LocalDateTimeColumn;
use App\Support\Locale\Display;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

/**
 * Goods received notes: each delivery against the invoice.
 */
class PurchaseReceiptsRelationManager extends RelationManager
{
    protected static string $relationship = 'receipts';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Deliveries');
    }

    #[On('purchase-updated')]
    public function refreshRows(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('receiver')->withSum('items', 'quantity'))
            ->defaultSort('received_at', 'desc')
            ->columns([
                CodeColumn::make('number')->label(__('Number')),
                LocalDateTimeColumn::make('received_at')->label(__('Received')),
                TextColumn::make('method')->label(__('How'))->badge()->color('gray'),
                TextColumn::make('items_sum_quantity')->label(__('Items'))->alignEnd()->formatStateUsing(fn ($state) => Display::number((int) $state)),
                TextColumn::make('receiver.name')->label(__('By')),
                TextColumn::make('notes')->label(__('Notes'))->placeholder('—')->wrap()->toggleable(),
            ])
            ->recordActions([PrintAction::make('goods-received')])
            ->emptyStateHeading(__('Nothing received yet'));
    }
}
