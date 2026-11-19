<?php

namespace App\Filament\Admin\Resources\Products\RelationManagers;

use App\Models\Branch;
use App\Models\BranchProductSetting;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\ProductService;
use App\Services\Permissions\BranchScopeResolver;
use App\Support\Locale\Display;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Minimum / maximum stock of this product per branch (overrides the product default).
 */
class BranchSettingsRelationManager extends RelationManager
{
    protected static string $relationship = 'branchSettings';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Minimum stock per station');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return app(BranchScopeResolver::class)->hasAny(Auth::user(), 'stock.view');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        /** @var User $user */
        $user = Auth::user();
        $scope = app(BranchScopeResolver::class);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $scope->apply($query->with('branch'), $user, 'stock.view'))
            ->description(fn () => __('Default for every station: :min. Set a different minimum where a station needs more or less.', [
                'min' => Display::number($this->product()->min_stock_default),
            ]))
            ->columns([
                TextColumn::make('branch.code')->label(__('Station'))->badge()->color('gray'),
                TextColumn::make('min_stock')->label(__('Minimum'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state)),
                TextColumn::make('max_stock')->label(__('Maximum'))->alignEnd()->placeholder('—')->formatStateUsing(fn ($state) => Display::number($state)),
                TextColumn::make('reorder_qty')->label(__('Reorder quantity'))->alignEnd()->placeholder('—')->formatStateUsing(fn ($state) => Display::number($state)),
            ])
            ->headerActions([
                $this->setAction()
                    ->label(__('Set station minimum'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->visible(fn () => $scope->hasAny($user, 'stock.min_levels')),
            ])
            ->recordActions([
                $this->setAction('edit')
                    ->label(__('Edit'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->fillForm(fn (BranchProductSetting $record) => $record->only(['branch_id', 'min_stock', 'max_stock', 'reorder_qty']))
                    ->visible(fn (BranchProductSetting $record) => $user->canInBranch('stock.min_levels', $record->branch_id)),
                Action::make('reset')
                    ->label(__('Use default'))
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (BranchProductSetting $record) => $user->canInBranch('stock.min_levels', $record->branch_id))
                    ->action(fn (BranchProductSetting $record) => app(ProductService::class)->setBranchMinimum($this->product(), $record->branch_id, null)),
            ])
            ->emptyStateHeading(__('Every station uses the default minimum'));
    }

    private function setAction(string $name = 'set'): Action
    {
        /** @var User $user */
        $user = Auth::user();

        return Action::make($name)
            ->schema([
                Select::make('branch_id')
                    ->label(__('Station'))
                    ->options(fn () => app(BranchScopeResolver::class)->selectableBranches($user, 'stock.min_levels')->get()->mapWithKeys(fn (Branch $b) => [$b->id => $b->label()]))
                    ->required()
                    ->native(false),
                TextInput::make('min_stock')->label(__('Minimum'))->numeric()->integer()->minValue(0)->required(),
                TextInput::make('max_stock')->label(__('Maximum'))->numeric()->integer()->minValue(0)->gte('min_stock'),
                TextInput::make('reorder_qty')->label(__('Reorder quantity'))->numeric()->integer()->minValue(1),
            ])
            ->action(function (array $data) use ($user) {
                abort_unless($user->canInBranch('stock.min_levels', (int) $data['branch_id']), 403);

                app(ProductService::class)->setBranchMinimum(
                    $this->product(),
                    (int) $data['branch_id'],
                    (int) $data['min_stock'],
                    filled($data['max_stock'] ?? null) ? (int) $data['max_stock'] : null,
                    filled($data['reorder_qty'] ?? null) ? (int) $data['reorder_qty'] : null,
                );

                Notification::make()->title(__('Minimum saved.'))->success()->send();
            });
    }

    private function product(): Product
    {
        /** @var Product */
        return $this->getOwnerRecord();
    }
}
