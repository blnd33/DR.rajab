<?php

namespace App\Filament\Admin\Resources\CashBoxes\RelationManagers;

use App\Models\CashBox;
use App\Support\Locale\Display;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Daily closings of the box.
 */
class BoxClosingsRelationManager extends RelationManager
{
    protected static string $relationship = 'closings';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Daily closings');
    }

    public function table(Table $table): Table
    {
        /** @var CashBox $box */
        $box = $this->getOwnerRecord();
        $money = fn ($state) => Display::money($state, $box->currency);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['counter', 'reviewer']))
            ->defaultSort('business_date', 'desc')
            ->columns([
                TextColumn::make('business_date')->label(__('Day'))->formatStateUsing(fn ($state) => Display::date($state)),
                TextColumn::make('expected_balance')->label(__('Expected'))->alignEnd()->formatStateUsing($money),
                TextColumn::make('counted_balance')->label(__('Counted'))->alignEnd()->formatStateUsing($money)->placeholder('—'),
                TextColumn::make('difference')->label(__('Difference'))->alignEnd()->formatStateUsing($money)
                    ->color(fn ($state) => (float) $state !== 0.0 ? 'danger' : null)->description(fn ($record) => $record->difference_reason),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('counter.name')->label(__('Counted by'))->placeholder('—'),
            ])
            ->emptyStateHeading(__('No closings yet'));
    }
}
