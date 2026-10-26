<?php

namespace App\Filament\Admin\Resources\CustomerReturns\RelationManagers;

use App\Filament\Admin\Resources\WriteOffs\WriteOffResource;
use App\Filament\Admin\Tables\Columns\ExpiryColumn;
use App\Filament\Admin\Tables\Columns\ProductColumn;
use App\Models\CustomerReturnItem;
use App\Support\Locale\Display;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ReturnItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Items');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product', 'stockUnit', 'writeOff']))
            ->paginated(false)
            ->columns([
                ProductColumn::make('product'),
                TextColumn::make('code')->label(__('Serial / lot'))->fontFamily(FontFamily::Mono)->extraAttributes(['dir' => 'ltr'])
                    ->state(fn (CustomerReturnItem $r) => $r->stockUnit->serial ?? $r->lot ?? '—'),
                ExpiryColumn::make('expiry_date'),
                TextColumn::make('quantity')->label(__('Quantity'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state)),
                TextColumn::make('previous_status')->label(__('Was'))->badge()->placeholder('—'),
                TextColumn::make('reason')->label(__('Reason'))->badge(),
                TextColumn::make('condition')->label(__('Condition'))->badge(),
                TextColumn::make('writeOff.number')->label(__('Write-off'))->fontFamily(FontFamily::Mono)->placeholder('—')
                    ->url(fn (CustomerReturnItem $r) => $r->writeOff !== null ? WriteOffResource::getUrl('view', ['record' => $r->writeOff]) : null),
            ]);
    }
}
