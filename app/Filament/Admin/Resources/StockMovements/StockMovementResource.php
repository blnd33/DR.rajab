<?php

namespace App\Filament\Admin\Resources\StockMovements;

use App\Enums\MovementType;
use App\Filament\Admin\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Admin\Resources\StockUnits\StockUnitResource;
use App\Filament\Admin\Tables\Columns\LocalDateTimeColumn;
use App\Filament\Admin\Tables\Columns\ProductColumn;
use App\Models\Branch;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;
use App\Support\Locale\Display;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * The stock ledger (append-only), filterable by product / serial / lot / type / branch / date.
 */
class StockMovementResource extends Resource
{
    protected static ?string $model = StockMovement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?int $navigationSort = 50;

    public static function getNavigationGroup(): ?string
    {
        return __('Stock');
    }

    public static function getNavigationLabel(): string
    {
        return __('Stock movement');
    }

    public static function getModelLabel(): string
    {
        return __('Stock movement');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Stock movements');
    }

    public static function getEloquentQuery(): Builder
    {
        /** @var User $user */
        $user = Auth::user();
        $branchIds = app(BranchScopeResolver::class)->branchIdsFor($user, 'stock.view');
        $query = parent::getEloquentQuery();

        // Movements touching any branch in scope (sending or receiving side).
        return match (true) {
            $branchIds === null => $query,
            $branchIds === [] => $query->whereRaw('1 = 0'),
            default => $query->where(fn (Builder $q) => $q->whereIn('from_branch_id', $branchIds)->orWhereIn('to_branch_id', $branchIds)),
        };
    }

    public static function table(Table $table): Table
    {
        /** @var User $user */
        $user = Auth::user();
        $scope = app(BranchScopeResolver::class);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product', 'stockUnit', 'fromBatch', 'toBatch', 'fromBranch', 'toBranch', 'user']))
            ->defaultSort('occurred_at', 'desc')
            ->columns([
                LocalDateTimeColumn::make('occurred_at')->label(__('When'))->sortable(),
                TextColumn::make('type')->label(__('Movement'))->badge(),
                ProductColumn::make('product'),
                TextColumn::make('code')
                    ->label(__('Serial / lot'))
                    ->fontFamily(FontFamily::Mono)
                    ->extraAttributes(['dir' => 'ltr'])
                    ->state(fn (StockMovement $record) => $record->stockUnit->serial ?? $record->toBatch->lot ?? $record->fromBatch->lot ?? '—')
                    ->url(fn (StockMovement $record) => $record->stockUnit !== null ? StockUnitResource::getUrl('view', ['record' => $record->stockUnit]) : null),
                TextColumn::make('quantity')->label(__('Qty'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state)),
                TextColumn::make('branches')
                    ->label(__('Station'))
                    ->state(fn (StockMovement $record) => $record->fromBranch !== null && $record->toBranch !== null && $record->from_branch_id !== $record->to_branch_id
                        ? $record->fromBranch->code.' → '.$record->toBranch->code
                        : ($record->toBranch ?? $record->fromBranch)?->code),
                TextColumn::make('reason')->label(__('Reason'))->limit(60)->wrap()->toggleable(),
                TextColumn::make('user.name')->label(__('By'))->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')->label(__('Movement'))->multiple()->options(MovementType::options()),
                SelectFilter::make('branch')
                    ->label(__('Station'))
                    ->options(fn () => $scope->apply(Branch::query()->orderBy('code'), $user, 'stock.view', 'id')->pluck('code', 'id'))
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->where(fn (Builder $q) => $q->where('from_branch_id', (int) $data['value'])->orWhere('to_branch_id', (int) $data['value']))
                        : $query),
                Filter::make('code')
                    ->label(__('Serial or lot'))
                    ->schema([TextInput::make('code')->label(__('Serial or lot'))->extraInputAttributes(['dir' => 'ltr'])])
                    ->query(function (Builder $query, array $data) {
                        $code = trim((string) ($data['code'] ?? ''));

                        return $code === '' ? $query : $query->where(fn (Builder $q) => $q
                            ->whereHas('stockUnit', fn ($u) => $u->where('serial', $code)->orWhere('lot', $code))
                            ->orWhereHas('toBatch', fn ($b) => $b->where('lot', $code))
                            ->orWhereHas('fromBatch', fn ($b) => $b->where('lot', $code)));
                    }),
                Filter::make('period')
                    ->schema([
                        DatePicker::make('from')->label(__('From'))->native(false)->displayFormat('Y-m-d'),
                        DatePicker::make('until')->label(__('Until'))->native(false)->displayFormat('Y-m-d'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->where('occurred_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->where('occurred_at', '<', Carbon::parse($d)->addDay()))),
            ])
            ->filtersFormColumns(2)
            ->emptyStateHeading(__('No stock movements yet'));
    }

    public static function getPages(): array
    {
        return ['index' => ListStockMovements::route('/')];
    }
}
