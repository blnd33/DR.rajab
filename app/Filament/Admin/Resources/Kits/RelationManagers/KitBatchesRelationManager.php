<?php

namespace App\Filament\Admin\Resources\Kits\RelationManagers;

use App\Enums\KitStatus;
use App\Enums\LocationType;
use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Filament\Admin\Tables\Columns\ExpiryColumn;
use App\Filament\Admin\Tables\Columns\ProductColumn;
use App\Models\Kit;
use App\Models\StockBatch;
use App\Services\Assets\KitService;
use App\Support\Locale\Display;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

/**
 * Lot consumables inside the kit (their own bucket per kit).
 */
class KitBatchesRelationManager extends RelationManager
{
    protected static string $relationship = 'batches';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Lots');
    }

    #[On('kit-updated')]
    public function refreshRows(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('product'))
            ->defaultSort('expiry_date')
            ->columns([
                ProductColumn::make('product'),
                CodeColumn::make('lot')->label(__('Lot'))->placeholder('—'),
                ExpiryColumn::make('expiry_date'),
                TextColumn::make('quantity')->label(__('Quantity'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state)),
                TextColumn::make('location_type')->label(__('Where'))->badge(),
            ])
            ->recordActions([
                Action::make('remove')
                    ->label(__('Take out'))
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('gray')
                    ->visible(fn (StockBatch $r) => $r->location_type === LocationType::Kit && $this->kit()->status === KitStatus::Available
                        && ServiceAction::user()->canInBranch('kits.prepare', $this->kit()->branch_id))
                    ->fillForm(fn (StockBatch $r) => ['quantity' => $r->quantity])
                    ->schema([TextInput::make('quantity')->label(__('Quantity back to the shelf'))->numeric()->integer()->minValue(1)->required()])
                    ->action(fn (StockBatch $record, array $data, Action $action) => ServiceAction::run($action, fn ($u) => app(KitService::class)->removeBatch($record, (int) $data['quantity'], $u), __('Taken out of the kit.'))),
            ])
            ->emptyStateHeading(__('No lots in this kit'));
    }

    private function kit(): Kit
    {
        /** @var Kit */
        return $this->getOwnerRecord();
    }
}
