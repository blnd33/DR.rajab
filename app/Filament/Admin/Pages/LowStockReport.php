<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Resources\Products\ProductResource;
use App\Filament\Admin\Resources\StockRequests\StockRequestResource;
use App\Filament\Admin\Tables\Columns\ProductColumn;
use App\Models\Branch;
use App\Models\LowStock;
use App\Models\User;
use App\Services\Catalog\ProductService;
use App\Services\Permissions\BranchScopeResolver;
use App\Services\Stock\LowStockDetector;
use App\Support\Locale\Display;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Products below their minimum on the shelf, per branch (Module 17).
 * "Request from another branch" is added with stock requests in Phase 2.
 */
class LowStockReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingDown;

    protected static ?int $navigationSort = 230;

    protected string $view = 'filament.pages.table-page';

    public static function getNavigationGroup(): ?string
    {
        return __('Stock');
    }

    public static function getNavigationLabel(): string
    {
        return __('Low stock');
    }

    public function getTitle(): string
    {
        return __('Low stock');
    }

    public function getSubheading(): ?string
    {
        return __('Products with less on the shelf than their minimum. Minimums are set per product or per station.');
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && app(BranchScopeResolver::class)->hasAny($user, 'stock.view');
    }

    public static function getNavigationBadge(): ?string
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return null;
        }

        $count = app(LowStockDetector::class)->query($user)->count();

        return $count > 0 ? Display::number($count) : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function table(Table $table): Table
    {
        /** @var User $user */
        $user = Auth::user();
        $scope = app(BranchScopeResolver::class);

        return $table
            ->query(fn () => app(LowStockDetector::class)->query($user))
            ->defaultSort('shortage', 'desc')
            ->columns([
                ProductColumn::make('product')
                    ->url(fn (LowStock $record) => ProductResource::getUrl('edit', ['record' => $record->product_id])),
                TextColumn::make('branch.code')->label(__('Station'))->badge()->color('gray'),
                TextColumn::make('qty_available')->label(__('On shelf'))->alignEnd()->weight('bold')->color('danger')->formatStateUsing(fn ($state) => Display::number($state)),
                TextColumn::make('min_stock')->label(__('Minimum'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state)),
                TextColumn::make('shortage')->label(__('Missing'))->alignEnd()->sortable()->formatStateUsing(fn ($state) => Display::number($state)),
                TextColumn::make('qty_total')
                    ->label(__('Elsewhere in station'))
                    ->alignEnd()
                    ->state(fn (LowStock $record) => $record->qty_total - $record->qty_available)
                    ->formatStateUsing(fn ($state) => Display::number($state))
                    ->tooltip(__('In instrument sets, at hospitals or in transit'))
                    ->toggleable(),
                TextColumn::make('reorder_qty')->label(__('Reorder quantity'))->alignEnd()->placeholder('—')->formatStateUsing(fn ($state) => Display::number($state))->toggleable(),
            ])
            ->filters([
                SelectFilter::make('branch_id')
                    ->label(__('Station'))
                    ->options(fn () => $scope->apply(Branch::query()->active()->orderBy('code'), $user, 'stock.view', 'id')->pluck('code', 'id')),
            ])
            ->recordActions([
                Action::make('request')
                    ->label(__('Request from another station'))
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->visible(fn (LowStock $record) => $user->canInBranch('stock_requests.create', $record->branch_id))
                    ->url(fn (LowStock $record) => StockRequestResource::getUrl('create', ['branch' => $record->branch_id, 'low' => 1, 'product' => $record->product_id])),
                Action::make('minimum')
                    ->label(__('Edit minimum'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->color('gray')
                    ->visible(fn (LowStock $record) => $user->canInBranch('stock.min_levels', $record->branch_id))
                    ->fillForm(fn (LowStock $record) => ['min_stock' => $record->min_stock])
                    ->schema([TextInput::make('min_stock')->label(__('Minimum in this station'))->numeric()->integer()->minValue(0)->required()])
                    ->action(function (LowStock $record, array $data) {
                        app(ProductService::class)->setBranchMinimum($record->product, $record->branch_id, (int) $data['min_stock']);
                        Notification::make()->title(__('Minimum saved.'))->success()->send();
                    }),
            ])
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle)
            ->emptyStateHeading(__('Nothing is below its minimum'))
            ->emptyStateDescription(__('Every product has at least its minimum on the shelf.'));
    }
}
