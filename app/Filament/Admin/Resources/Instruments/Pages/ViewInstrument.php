<?php

namespace App\Filament\Admin\Resources\Instruments\Pages;

use App\Enums\InstrumentStatus;
use App\Filament\Admin\Resources\Instruments\InstrumentResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\Instrument;
use App\Models\User;
use App\Services\Assets\InstrumentService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewInstrument extends ViewRecord
{
    protected static string $resource = InstrumentResource::class;

    public function getTitle(): string
    {
        return __('Instrument :code', ['code' => $this->instrument()->code]);
    }

    protected function getHeaderActions(): array
    {
        $loose = fn (array $statuses) => fn () => $this->instrument()->kit_id === null && in_array($this->instrument()->status, $statuses, true)
            && ServiceAction::user()->canInBranch('instruments.manage', $this->instrument()->branch_id);
        $service = fn () => app(InstrumentService::class);
        $note = Textarea::make('note')->label(__('Note'))->rows(2);

        return [
            EditAction::make(),
            ActionGroup::make([
                Action::make('maintenance_in')->label(__('Send to maintenance'))->icon(Heroicon::OutlinedWrenchScrewdriver)
                    ->visible($loose([InstrumentStatus::Available, InstrumentStatus::Damaged]))
                    ->schema([$note])
                    ->action(fn (array $data, Action $action) => $this->run($action, fn ($u) => $service()->sendToMaintenance($this->instrument(), $u, $data['note'] ?? null))),
                Action::make('maintenance_out')->label(__('Back from maintenance'))->icon(Heroicon::OutlinedCheck)
                    ->visible($loose([InstrumentStatus::InMaintenance]))
                    ->schema([$note])
                    ->action(fn (array $data, Action $action) => $this->run($action, fn ($u) => $service()->backFromMaintenance($this->instrument(), $u, $data['note'] ?? null))),
                Action::make('missing')->label(__('Mark missing'))->icon(Heroicon::OutlinedQuestionMarkCircle)->color('danger')
                    ->visible($loose([InstrumentStatus::Available, InstrumentStatus::InMaintenance, InstrumentStatus::Damaged]))
                    ->schema([Textarea::make('note')->label(__('Reason'))->required()->rows(2)])
                    ->action(fn (array $data, Action $action) => $this->run($action, fn ($u) => $service()->markMissing($this->instrument(), $u, $data['note']))),
                Action::make('found')->label(__('Mark found'))->icon(Heroicon::OutlinedCheckCircle)->color('success')
                    ->visible($loose([InstrumentStatus::Missing]))
                    ->schema([$note])
                    ->action(fn (array $data, Action $action) => $this->run($action, fn ($u) => $service()->markFound($this->instrument(), $u, $data['note'] ?? null))),
                Action::make('holder')->label(__('Change holder'))->icon(Heroicon::OutlinedUser)
                    ->visible(fn () => ServiceAction::user()->canInBranch('instruments.manage', $this->instrument()->branch_id))
                    ->fillForm(fn () => ['holder_user_id' => $this->instrument()->holder_user_id])
                    ->schema([Select::make('holder_user_id')->label(__('Held by'))->options(fn () => User::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))->searchable()->native(false)])
                    ->action(fn (array $data, Action $action) => $this->run($action, fn ($u) => $service()->assignHolder($this->instrument(), filled($data['holder_user_id'] ?? null) ? User::query()->find($data['holder_user_id']) : null, $u))),
                Action::make('retire')->label(__('Retire'))->icon(Heroicon::OutlinedArchiveBoxXMark)->color('danger')
                    ->visible($loose([InstrumentStatus::Available, InstrumentStatus::InMaintenance, InstrumentStatus::Damaged, InstrumentStatus::Missing]))
                    ->schema([Textarea::make('note')->label(__('Reason'))->required()->rows(2)])
                    ->action(fn (array $data, Action $action) => $this->run($action, fn ($u) => $service()->retire($this->instrument(), $u, $data['note']))),
            ])->label(__('Status'))->button()->color('gray'),
        ];
    }

    private function run(Action $action, callable $callback): void
    {
        ServiceAction::run($action, $callback, __('Saved.'));
        $this->record = $this->instrument()->fresh();
    }

    private function instrument(): Instrument
    {
        /** @var Instrument */
        return $this->getRecord();
    }
}
