<?php

namespace App\Filament\Admin\Resources\Customers\RelationManagers;

use App\Filament\Admin\Resources\CustomerReturns\CustomerReturnResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Filament\Admin\Tables\Columns\LocalDateTimeColumn;
use App\Models\CustomerReturn;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Items this hospital gave back (in the user's branches).
 */
class CustomerReturnsRelationManager extends RelationManager
{
    protected static string $relationship = 'returns';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Returns');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return ServiceAction::user()->can('viewAny', CustomerReturn::class);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => ServiceAction::scoped($query->with('branch')->withSum('items', 'quantity'), 'returns.view_any', ['branch_id']))
            ->defaultSort('received_at', 'desc')
            ->columns([
                CodeColumn::make('number')->label(__('Number')),
                TextColumn::make('branch.code')->label(__('Station'))->badge()->color('gray'),
                TextColumn::make('items_sum_quantity')->label(__('Items'))->alignEnd(),
                LocalDateTimeColumn::make('received_at')->label(__('Received')),
                TextColumn::make('status')->label(__('Status'))->badge(),
            ])
            ->recordUrl(fn (CustomerReturn $r) => CustomerReturnResource::getUrl('view', ['record' => $r]))
            ->emptyStateHeading(__('No customer returns'));
    }
}
