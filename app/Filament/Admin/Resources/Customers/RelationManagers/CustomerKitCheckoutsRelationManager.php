<?php

namespace App\Filament\Admin\Resources\Customers\RelationManagers;

use App\Filament\Admin\Resources\KitCheckouts\KitCheckoutResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Filament\Admin\Tables\Columns\LocalDateTimeColumn;
use App\Models\KitCheckout;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Kits lent to this hospital (in the user's branches).
 */
class CustomerKitCheckoutsRelationManager extends RelationManager
{
    protected static string $relationship = 'kitCheckouts';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Kits');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return ServiceAction::user()->can('viewAny', KitCheckout::class);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => ServiceAction::scoped($query->with(['kit', 'doctor']), 'kits.view_any', ['branch_id']))
            ->defaultSort('checked_out_at', 'desc')
            ->columns([
                CodeColumn::make('number')->label(__('Number')),
                TextColumn::make('kit.code')->label(__('Kit')),
                TextColumn::make('doctor.name')->label(__('Doctor'))->placeholder('—'),
                LocalDateTimeColumn::make('checked_out_at')->label(__('Out')),
                TextColumn::make('status')->label(__('Status'))->badge(),
            ])
            ->recordUrl(fn (KitCheckout $r) => KitCheckoutResource::getUrl('view', ['record' => $r]))
            ->emptyStateHeading(__('No kits lent to this hospital yet'));
    }
}
