<?php

namespace App\Filament\Admin\Resources\Products\RelationManagers;

use App\Filament\Admin\Resources\StockBatches\StockBatchResource;
use App\Filament\Admin\Resources\StockUnits\StockUnitResource;
use App\Models\Product;
use App\Models\StockOnHand;
use App\Models\StockUnit;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;
use App\Services\Stock\FefoPicker;
use App\Support\ExpiryDisplay;
use App\Support\Locale\Display;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Quantities of this product per branch (shelf, kits, hospitals, in transit) with the
 * FEFO suggestion: the soonest-expiring valid item to use next.
 */
class StockByBranchRelationManager extends RelationManager
{
    protected static string $relationship = 'onHand';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Stock by branch');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return app(BranchScopeResolver::class)->hasAny(Auth::user(), 'stock.view');
    }

    public function table(Table $table): Table
    {
        /** @var User $user */
        $user = Auth::user();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => app(BranchScopeResolver::class)->apply($query->with('branch'), $user, 'stock.view'))
            ->defaultSort('branch_id')
            ->paginated(false)
            ->columns([
                TextColumn::make('branch.code')->label(__('Branch'))->badge()->color('gray'),
                TextColumn::make('qty_available')->label(__('On shelf'))->alignEnd()->weight('bold')->formatStateUsing(fn ($state) => Display::number($state)),
                TextColumn::make('qty_in_kits')->label(__('In kits'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state)),
                TextColumn::make('qty_at_hospital')->label(__('At hospitals'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state)),
                TextColumn::make('qty_in_transit')->label(__('In transit'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state)),
                TextColumn::make('fefo')
                    ->label(__('Use next (FEFO)'))
                    ->html()
                    ->state(function (StockOnHand $record) {
                        $next = app(FefoPicker::class)->suggest($this->product(), $record->branch_id);

                        if ($next === null) {
                            return e(__('Nothing valid on the shelf'));
                        }

                        $code = $next instanceof StockUnit ? $next->serial : ($next->lot ?? '—');
                        $expiry = ExpiryDisplay::for($next->expiry_date);

                        return '<span class="ot-code" dir="ltr">'.e($code).'</span> · '.e($expiry['date'] ?: __('No expiry'));
                    }),
            ])
            ->recordActions([
                Action::make('units')
                    ->label(fn () => $this->product()->isSerialTracked() ? __('Units') : __('Lots'))
                    ->icon(Heroicon::OutlinedListBullet)
                    ->color('gray')
                    ->url(fn (StockOnHand $record) => $this->product()->isSerialTracked()
                        ? StockUnitResource::getUrl('index', ['filters' => ['product_id' => ['value' => $record->product_id], 'branch_id' => ['value' => $record->branch_id]]])
                        : StockBatchResource::getUrl('index', ['filters' => ['product_id' => ['value' => $record->product_id], 'branch_id' => ['value' => $record->branch_id]]])),
            ])
            ->emptyStateHeading(__('No stock of this product'))
            ->emptyStateDescription(__('Receive stock or import it from Excel.'));
    }

    private function product(): Product
    {
        /** @var Product */
        return $this->getOwnerRecord();
    }
}
