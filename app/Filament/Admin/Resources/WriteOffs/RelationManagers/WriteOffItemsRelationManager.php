<?php

namespace App\Filament\Admin\Resources\WriteOffs\RelationManagers;

use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\ExpiryColumn;
use App\Filament\Admin\Tables\Columns\ProductColumn;
use App\Models\WriteOffItem;
use App\Services\Permissions\BranchScopeResolver;
use App\Support\Locale\Display;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WriteOffItemsRelationManager extends RelationManager
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
                TextColumn::make('code')->label(__('Serial / lot'))->fontFamily(FontFamily::Mono)->extraAttributes(['dir' => 'ltr'])->state(fn (WriteOffItem $r) => $r->code()),
                ExpiryColumn::make('expiry')->state(fn (WriteOffItem $r) => ($r->stockUnit ?? $r->stockBatch)?->expiry_date)->sortable(false),
                TextColumn::make('quantity')->label(__('Quantity'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state)),
                TextColumn::make('target_status')->label(__('Becomes'))->badge(),
                TextColumn::make('unit_cost')->label(__('Unit cost'))->alignEnd()
                    ->state(fn (WriteOffItem $r) => Display::number($r->unit_cost, 2).' '.$r->cost_currency)
                    ->visible(fn () => app(BranchScopeResolver::class)->hasAny(ServiceAction::user(), 'stock.view_cost')),
            ]);
    }
}
