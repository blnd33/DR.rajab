<?php

namespace App\Filament\Admin\Resources\SupplierReturns\RelationManagers;

use App\Filament\Admin\Tables\Columns\ProductColumn;
use App\Models\SupplierReturnItem;
use App\Support\Locale\Display;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SupplierReturnItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Items');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product', 'stockUnit', 'stockBatch']))
            ->paginated(false)
            ->columns([
                ProductColumn::make('product'),
                TextColumn::make('code')->label(__('Serial / lot'))->fontFamily(FontFamily::Mono)->extraAttributes(['dir' => 'ltr'])->state(fn (SupplierReturnItem $r) => $r->code()),
                TextColumn::make('quantity')->label(__('Quantity'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state)),
                TextColumn::make('reason')->label(__('Reason'))->badge(),
                TextColumn::make('unit_credit')->label(__('Credit per unit'))->alignEnd()->state(fn (SupplierReturnItem $r) => Display::number($r->unit_credit, 2).' '.$r->currency),
            ]);
    }
}
