<?php

namespace App\Filament\Admin\Resources\Products\Tables;

use App\Enums\TrackingType;
use App\Filament\Admin\Actions\PrintLabelsAction;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;
use App\Support\Locale\Display;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                /** @var User $user */
                $user = Auth::user();
                $scope = app(BranchScopeResolver::class);

                $query->with(['category.parent', 'brand', 'uom'])
                    ->withSum(['onHand as on_hand_total' => fn ($q) => $scope->apply($q, $user, 'stock.view')], 'qty_total')
                    ->withSum(['onHand as on_shelf_total' => fn ($q) => $scope->apply($q, $user, 'stock.view')], 'qty_available');
            })
            ->defaultSort('sku')
            ->columns([
                ImageColumn::make('photo_path')->label('')->disk('public')->circular()->imageSize(36)->toggleable(),
                CodeColumn::make('sku')->label(__('SKU'))->sortable()->searchable(),
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->description(fn (Product $record) => $record->size)
                    ->wrap()
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereIn('products.id', Product::query()->search($search)->select('id'))),
                TextColumn::make('category')
                    ->label(__('Category'))
                    ->state(fn (Product $record) => $record->category?->path())
                    ->toggleable(),
                TextColumn::make('brand.name')->label(__('Brand'))->toggleable(),
                TextColumn::make('tracking_type')->label(__('Tracking'))->badge()->color('gray'),
                TextColumn::make('on_shelf_total')
                    ->label(__('On shelf'))
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => Display::number((int) $state))
                    ->description(fn (Product $record) => (int) $record->getAttribute('on_hand_total') !== (int) $record->getAttribute('on_shelf_total')
                        ? __(':count in total', ['count' => Display::number((int) $record->getAttribute('on_hand_total'))])
                        : null),
                TextColumn::make('min_stock_default')->label(__('Min.'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state))->toggleable(),
                CodeColumn::make('gtin')->label(__('GTIN'))->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')->label(__('Active'))->boolean()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category_id')->label(__('Category'))->options(fn () => Category::options())->searchable(),
                SelectFilter::make('brand_id')->label(__('Brand'))->options(fn () => Brand::query()->orderBy('name')->pluck('name', 'id'))->searchable(),
                SelectFilter::make('tracking_type')->label(__('Tracking'))->options(TrackingType::options()),
                TernaryFilter::make('is_implant')->label(__('Implant')),
                TernaryFilter::make('is_active')->label(__('Active'))->default(true),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    PrintLabelsAction::make('product')
                        ->visible(fn () => app(BranchScopeResolver::class)->hasAny(Auth::user(), 'products.print_labels')),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    PrintLabelsAction::bulk('product')
                        ->visible(fn () => app(BranchScopeResolver::class)->hasAny(Auth::user(), 'products.print_labels')),
                ]),
            ])
            ->searchPlaceholder(__('SKU, GTIN, barcode or name'))
            ->emptyStateHeading(__('No products yet'))
            ->emptyStateDescription(__('Add products one by one, or import the catalog from Excel.'));
    }
}
