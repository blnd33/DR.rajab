<?php

namespace App\Filament\Admin\Resources\Surgeries\RelationManagers;

use App\Filament\Admin\Resources\StockUnits\StockUnitResource;
use App\Filament\Admin\Tables\Columns\ExpiryColumn;
use App\Filament\Admin\Tables\Columns\ProductColumn;
use App\Models\SurgeryItem;
use App\Services\Clinical\SurgeryService;
use App\Support\Locale\Display;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

/**
 * Implants and products of a surgery, with where they came from and any warning.
 */
class SurgeryItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Implants and products used');
    }

    #[On('surgery-updated')]
    public function refreshItems(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product', 'stockUnit', 'stockBatch', 'kit']))
            ->defaultSort('id')
            ->columns([
                ProductColumn::make('product'),
                TextColumn::make('code')->label(__('Serial / lot'))->fontFamily(FontFamily::Mono)->extraAttributes(['dir' => 'ltr'])
                    ->state(fn (SurgeryItem $r) => $r->code())
                    ->url(fn (SurgeryItem $r) => $r->stock_unit_id !== null ? StockUnitResource::getUrl('view', ['record' => $r->stock_unit_id]) : null),
                ExpiryColumn::make('expiry')->state(fn (SurgeryItem $r) => ($r->stockUnit ?? $r->stockBatch)?->expiry_date)->sortable(false),
                TextColumn::make('quantity')->label(__('Quantity'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state)),
                TextColumn::make('source')->label(__('From'))->badge()->description(fn (SurgeryItem $r) => $r->kit?->code),
                TextColumn::make('warnings')->label(__('Warnings'))->badge()->color('warning')
                    ->state(fn (SurgeryItem $r) => array_map(fn ($w) => SurgeryService::warningLabel($w), $r->warnings ?? []))->placeholder('—'),
                TextColumn::make('override_reason')->label(__('Expired — reason'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reversed_at')->label(__('Put back'))->placeholder('—')
                    ->formatStateUsing(fn ($state) => Display::date($state))->description(fn (SurgeryItem $r) => $r->reversal_reason),
            ])
            ->emptyStateHeading(__('No items'));
    }
}
