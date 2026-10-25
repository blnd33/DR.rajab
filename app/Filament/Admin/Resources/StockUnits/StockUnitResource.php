<?php

namespace App\Filament\Admin\Resources\StockUnits;

use App\Enums\StockStatus;
use App\Filament\Admin\Actions\PrintLabelsAction;
use App\Filament\Admin\Actions\StockCorrectionActions;
use App\Filament\Admin\Infolists\ExpiryEntry;
use App\Filament\Admin\Infolists\LocalDateTimeEntry;
use App\Filament\Admin\Resources\StockUnits\Pages\ListStockUnits;
use App\Filament\Admin\Resources\StockUnits\Pages\ViewStockUnit;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Filament\Admin\Tables\Columns\ExpiryColumn;
use App\Filament\Admin\Tables\Columns\LocalDateTimeColumn;
use App\Filament\Admin\Tables\Columns\ProductColumn;
use App\Filament\Admin\Tables\Filters\ExpiryLevelFilter;
use App\Models\Branch;
use App\Models\Product;
use App\Models\StockUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;
use App\Support\Locale\Display;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Serial-tracked units (Module 4) — branch-scoped, read-only; changes go through
 * StockMovementService actions (correct, status override).
 */
class StockUnitResource extends Resource
{
    protected static ?string $model = StockUnit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'serial';

    public static function getNavigationGroup(): ?string
    {
        return __('Stock');
    }

    public static function getModelLabel(): string
    {
        return __('Serial unit');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Serial units');
    }

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['serial', 'lot'];
    }

    public static function getEloquentQuery(): Builder
    {
        /** @var User $user */
        $user = Auth::user();

        return app(BranchScopeResolver::class)->apply(parent::getEloquentQuery(), $user, 'stock.view');
    }

    public static function canSeeCost(): bool
    {
        return app(BranchScopeResolver::class)->hasAny(Auth::user(), 'stock.view_cost');
    }

    public static function table(Table $table): Table
    {
        /** @var User $user */
        $user = Auth::user();
        $scope = app(BranchScopeResolver::class);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product', 'branch', 'location']))
            ->defaultSort('expiry_date')
            ->columns([
                CodeColumn::make('serial')->label(__('Serial'))->searchable()->sortable(),
                ProductColumn::make('product'),
                CodeColumn::make('lot')->label(__('Lot'))->searchable()->placeholder('—'),
                ExpiryColumn::make('expiry_date'),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('branch.code')->label(__('Branch'))->badge()->color('gray'),
                TextColumn::make('location')
                    ->label(__('Where'))
                    ->state(fn (StockUnit $record) => $record->locationLabel())
                    ->toggleable(),
                TextColumn::make('unit_cost')
                    ->label(__('True cost'))
                    ->alignEnd()
                    ->state(fn (StockUnit $record) => Display::number($record->unit_cost, 2).' '.$record->cost_currency)
                    ->visible(fn () => self::canSeeCost())
                    ->toggleable(),
                LocalDateTimeColumn::make('received_at')->label(__('Received'))->dateOnly()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->multiple()
                    ->options(StockStatus::options())
                    ->default(array_map(fn (StockStatus $s) => $s->value, StockStatus::onHand())),
                SelectFilter::make('branch_id')
                    ->label(__('Branch'))
                    ->options(fn () => $scope->apply(Branch::query()->orderBy('code'), $user, 'stock.view', 'id')->pluck('code', 'id')),
                SelectFilter::make('product_id')
                    ->label(__('Product'))
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Product::query()->search($search)->limit(30)->get()->mapWithKeys(fn (Product $p) => [$p->id => $p->label()]))
                    ->getOptionLabelUsing(fn ($value) => Product::query()->find($value)?->label())
                    ->options(fn () => []),
                ExpiryLevelFilter::make('expiry_level'),
                SelectFilter::make('supplier_id')->label(__('Supplier'))->options(fn () => Supplier::options())->searchable(),
            ])
            ->filtersFormColumns(2)
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make([
                    PrintLabelsAction::make('unit')->visible(fn (StockUnit $record) => $user->can('printLabel', $record)),
                    StockCorrectionActions::correctUnit(),
                    StockCorrectionActions::overrideStatus(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    PrintLabelsAction::bulk('unit')->visible(fn () => $scope->hasAny($user, 'products.print_labels')),
                ]),
            ])
            ->searchPlaceholder(__('Serial or lot'))
            ->emptyStateHeading(__('No serial units'))
            ->emptyStateDescription(__('Receive stock or import it from Excel.'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(['default' => 1, 'lg' => 3])->components([
            Section::make(__('Unit'))
                ->columnSpan(['lg' => 1])
                ->schema([
                    TextEntry::make('serial')->label(__('Serial'))->fontFamily(FontFamily::Mono)->copyable()->extraAttributes(['dir' => 'ltr']),
                    TextEntry::make('product')->label(__('Product'))->state(fn (StockUnit $record) => $record->product->label()),
                    TextEntry::make('lot')->label(__('Lot'))->fontFamily(FontFamily::Mono)->placeholder('—')->extraAttributes(['dir' => 'ltr']),
                    ExpiryEntry::make('expiry_date'),
                    TextEntry::make('production_date')->label(__('Production date'))->state(fn (StockUnit $record) => Display::date($record->production_date))->placeholder('—'),
                    TextEntry::make('status')->label(__('Status'))->badge(),
                    TextEntry::make('branch.code')->label(__('Branch'))->badge()->color('gray'),
                    TextEntry::make('location')->label(__('Where'))->state(fn (StockUnit $record) => $record->locationLabel()),
                    TextEntry::make('supplier.name')->label(__('Supplier'))->placeholder('—'),
                    TextEntry::make('unit_cost')
                        ->label(__('True cost'))
                        ->state(fn (StockUnit $record) => Display::number($record->unit_cost, 2).' '.$record->cost_currency)
                        ->helperText(fn (StockUnit $record) => __('Purchase :base + landed :landed', [
                            'base' => Display::number($record->base_unit_cost, 2),
                            'landed' => Display::number($record->landed_unit_cost, 2),
                        ]))
                        ->visible(fn () => self::canSeeCost()),
                    LocalDateTimeEntry::make('received_at')->label(__('Received')),
                ]),
            Section::make(__('History'))
                ->description(__('Every movement of this unit, newest first. Entries can never be changed.'))
                ->columnSpan(['lg' => 2])
                ->schema([
                    ViewEntry::make('history')->hiddenLabel()->view('filament.stock.serial-history'),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockUnits::route('/'),
            'view' => ViewStockUnit::route('/{record}'),
        ];
    }
}
