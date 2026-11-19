<?php

namespace App\Filament\Admin\Resources\Kits\RelationManagers;

use App\Filament\Admin\Resources\KitCheckouts\KitCheckoutResource;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Filament\Admin\Tables\Columns\LocalDateTimeColumn;
use App\Models\KitCheckout;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Every time the kit went out to a hospital.
 */
class KitCheckoutsRelationManager extends RelationManager
{
    protected static string $relationship = 'checkouts';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Checkouts');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['customer', 'doctor']))
            ->defaultSort('checked_out_at', 'desc')
            ->columns([
                CodeColumn::make('number')->label(__('Number')),
                TextColumn::make('customer.code')->label(__('Hospital'))->badge()->color('gray'),
                TextColumn::make('doctor.name')->label(__('Doctor'))->placeholder('—'),
                LocalDateTimeColumn::make('checked_out_at')->label(__('Out')),
                LocalDateTimeColumn::make('returned_at')->label(__('Back'))->placeholder('—'),
                TextColumn::make('status')->label(__('Status'))->badge(),
            ])
            ->recordUrl(fn (KitCheckout $r) => KitCheckoutResource::getUrl('view', ['record' => $r]))
            ->emptyStateHeading(__('This instrument set has not been out yet'));
    }
}
