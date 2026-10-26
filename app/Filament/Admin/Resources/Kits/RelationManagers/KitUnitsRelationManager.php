<?php

namespace App\Filament\Admin\Resources\Kits\RelationManagers;

use App\Enums\KitStatus;
use App\Enums\StockStatus;
use App\Filament\Admin\Resources\StockUnits\StockUnitResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Filament\Admin\Tables\Columns\ExpiryColumn;
use App\Filament\Admin\Tables\Columns\ProductColumn;
use App\Models\Kit;
use App\Models\StockUnit;
use App\Services\Assets\KitService;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

/**
 * Serial implants inside the kit (stock of the branch, location "inside kit X").
 */
class KitUnitsRelationManager extends RelationManager
{
    protected static string $relationship = 'units';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Implants');
    }

    #[On('kit-updated')]
    public function refreshRows(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('product')->whereIn('status', [StockStatus::InKit, StockStatus::OutAtHospital]))
            ->defaultSort('expiry_date')
            ->columns([
                ProductColumn::make('product'),
                CodeColumn::make('serial')->label(__('Serial'))->searchable()
                    ->url(fn (StockUnit $r) => StockUnitResource::getUrl('view', ['record' => $r])),
                CodeColumn::make('lot')->label(__('Lot'))->placeholder('—'),
                ExpiryColumn::make('expiry_date'),
                TextColumn::make('status')->label(__('Status'))->badge(),
            ])
            ->recordActions([
                Action::make('remove')
                    ->label(__('Take out'))
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('gray')
                    ->visible(fn () => $this->kit()->status === KitStatus::Available && ServiceAction::user()->canInBranch('kits.prepare', $this->kit()->branch_id))
                    ->requiresConfirmation()
                    ->modalDescription(__('The unit goes back on the branch shelf.'))
                    ->action(fn (StockUnit $record, Action $action) => ServiceAction::run($action, fn ($u) => app(KitService::class)->removeUnit($record, $u), __('Taken out of the kit.'))),
            ])
            ->emptyStateHeading(__('No implants in this kit'));
    }

    private function kit(): Kit
    {
        /** @var Kit */
        return $this->getOwnerRecord();
    }
}
