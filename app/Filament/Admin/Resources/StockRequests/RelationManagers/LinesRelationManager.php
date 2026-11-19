<?php

namespace App\Filament\Admin\Resources\StockRequests\RelationManagers;

use App\Filament\Admin\Resources\Transfers\TransferResource;
use App\Filament\Admin\Tables\Columns\ProductColumn;
use App\Models\StockOnHand;
use App\Models\StockRequest;
use App\Models\StockRequestItem;
use App\Support\Locale\Display;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Products');
    }

    #[On('request-updated')]
    public function refreshLines(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        /** @var StockRequest $request */
        $request = $this->getOwnerRecord();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product', 'sourceBranch', 'transfer']))
            ->paginated(false)
            ->columns([
                ProductColumn::make('product'),
                TextColumn::make('quantity_requested')->label(__('Asked'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state)),
                TextColumn::make('quantity_approved')->label(__('Approved'))->alignEnd()->placeholder('—')->formatStateUsing(fn ($state) => Display::number($state)),
                TextColumn::make('on_hand')->label(__('On shelf there now'))->alignEnd()
                    ->state(fn (StockRequestItem $r) => StockOnHand::query()->where('branch_id', $request->requesting_branch_id)->where('product_id', $r->product_id)->value('qty_available') ?? 0)
                    ->formatStateUsing(fn ($state) => Display::number((int) $state)),
                TextColumn::make('sourceBranch.code')->label(__('From station'))->placeholder('—'),
                TextColumn::make('transfer.number')->label(__('Transfer'))->fontFamily(FontFamily::Mono)->placeholder('—')
                    ->url(fn (StockRequestItem $r) => $r->transfer !== null ? TransferResource::getUrl('view', ['record' => $r->transfer]) : null),
                TextColumn::make('transfer.status')->label(__('Transfer status'))->badge()->placeholder('—'),
            ]);
    }
}
