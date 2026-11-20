<?php

namespace App\Filament\Admin\Resources\StockBatches;

use App\Enums\LocationType;
use App\Filament\Admin\Actions\PrintLabelsAction;
use App\Filament\Admin\Actions\StockCorrectionActions;
use App\Filament\Admin\Resources\StockBatches\Pages\ListStockBatches;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Filament\Admin\Tables\Columns\ExpiryColumn;
use App\Filament\Admin\Tables\Columns\ProductColumn;
use App\Filament\Admin\Tables\Filters\ExpiryLevelFilter;
use App\Models\Branch;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;
use App\Support\Locale\Display;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Lot-tracked stock buckets (screws, plates, cement…) — branch-scoped, read-only.
 */
class StockBatchResource extends Resource
{
    protected static ?string $model = StockBatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquare3Stack3d;

    protected static ?int $navigationSort = 220;

    public static function getNavigationGroup(): ?string
    {
        return __('Stock');
    }

    public static function getModelLabel(): string
    {
        return __('Lot');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Lots');
    }

    public static function getEloquentQuery(): Builder
    {
        /** @var User $user */
        $user = Auth::user();

        return app(BranchScopeResolver::class)->apply(parent::getEloquentQuery(), $user, 'stock.view');
    }

    public static function table(Table $table): Table
    {
        /** @var User $user */
        $user = Auth::user();
        $scope = app(BranchScopeResolver::class);
        $canSeeCost = $scope->hasAny($user, 'stock.view_cost');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product', 'branch', 'location']))
            ->defaultSort('expiry_date')
            ->columns([
                ProductColumn::make('product'),
                CodeColumn::make('lot')->label(__('Lot'))->searchable()->placeholder('—'),
                ExpiryColumn::make('expiry_date'),
                TextColumn::make('quantity')->label(__('Quantity'))->alignEnd()->weight('bold')->sortable()->formatStateUsing(fn ($state) => Display::number($state)),
                TextColumn::make('branch.code')->label(__('Station'))->badge()->color('gray'),
                TextColumn::make('location')->label(__('Where'))->state(fn (StockBatch $record) => $record->locationLabel())->toggleable(),
                TextColumn::make('unit_cost')
                    ->label(__('True cost'))
                    ->alignEnd()
                    ->state(fn (StockBatch $record) => Display::number($record->unit_cost, 2).' '.$record->cost_currency)
                    ->description(fn (StockBatch $record) => __('Value :value', ['value' => Display::number($record->value(), 2)]))
                    ->visible($canSeeCost)
                    ->toggleable(),
            ])
            ->filters([
                Filter::make('on_hand')->label(__('Only with quantity'))->default()->query(fn (Builder $query) => $query->where('quantity', '>', 0)),
                SelectFilter::make('branch_id')
                    ->label(__('Station'))
                    ->options(fn () => $scope->apply(Branch::query()->orderBy('code'), $user, 'stock.view', 'id')->pluck('code', 'id')),
                SelectFilter::make('product_id')
                    ->label(__('Product'))
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Product::query()->search($search)->limit(30)->get()->mapWithKeys(fn (Product $p) => [$p->id => $p->label()]))
                    ->getOptionLabelUsing(fn ($value) => Product::query()->find($value)?->label())
                    ->options(fn () => []),
                SelectFilter::make('location_type')->label(__('Where'))->options(collect(LocationType::batchValues())->mapWithKeys(fn ($v) => [$v => LocationType::from($v)->getLabel()])->all()),
                ExpiryLevelFilter::make('expiry_level'),
            ])
            ->filtersFormColumns(2)
            ->recordActions([
                ActionGroup::make([
                    PrintLabelsAction::make('batch')->visible(fn (StockBatch $record) => $user->can('printLabel', $record)),
                    StockCorrectionActions::correctBatch(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    PrintLabelsAction::bulk('batch')->visible(fn () => $scope->hasAny($user, 'products.print_labels')),
                ]),
            ])
            ->searchPlaceholder(__('Lot'))
            ->emptyStateHeading(__('No lots'))
            ->emptyStateDescription(__('Receive stock or import it from Excel.'));
    }

    public static function getPages(): array
    {
        return ['index' => ListStockBatches::route('/')];
    }
}
