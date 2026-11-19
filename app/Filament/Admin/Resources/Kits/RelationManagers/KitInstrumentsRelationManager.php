<?php

namespace App\Filament\Admin\Resources\Kits\RelationManagers;

use App\Enums\KitStatus;
use App\Filament\Admin\Actions\PrintLabelsAction;
use App\Filament\Admin\Resources\Instruments\InstrumentResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Models\Instrument;
use App\Models\Kit;
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
 * Instruments that belong to the kit.
 */
class KitInstrumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'instruments';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Instruments');
    }

    #[On('kit-updated')]
    public function refreshRows(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('type'))
            ->defaultSort('code')
            ->columns([
                CodeColumn::make('code')->label(__('Code'))
                    ->url(fn (Instrument $r) => InstrumentResource::getUrl('view', ['record' => $r])),
                TextColumn::make('type.name')->label(__('Instrument type')),
                CodeColumn::make('manufacturer_serial')->label(__('Maker serial'))->placeholder('—')->toggleable(),
                TextColumn::make('status')->label(__('Status'))->badge(),
            ])
            ->headerActions([
                PrintLabelsAction::ids('instrument', fn () => $this->kit()->instruments()->orderBy('code')->pluck('id')->map(fn ($id) => (int) $id)->all())
                    ->label(__('Print all labels')),
            ])
            ->toolbarActions([PrintLabelsAction::bulk('instrument')])
            ->recordActions([
                Action::make('remove')
                    ->label(__('Take out'))
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('gray')
                    ->visible(fn () => $this->kit()->status === KitStatus::Available && ServiceAction::user()->canInBranch('kits.prepare', $this->kit()->branch_id))
                    ->requiresConfirmation()
                    ->action(fn (Instrument $record, Action $action) => ServiceAction::run($action, fn ($u) => app(KitService::class)->removeInstrument($record, $u), __('Taken out of the instrument set.'))),
            ])
            ->emptyStateHeading(__('No instruments in this instrument set'));
    }

    private function kit(): Kit
    {
        /** @var Kit */
        return $this->getOwnerRecord();
    }
}
