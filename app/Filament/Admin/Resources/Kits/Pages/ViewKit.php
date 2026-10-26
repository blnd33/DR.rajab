<?php

namespace App\Filament\Admin\Resources\Kits\Pages;

use App\Enums\KitStatus;
use App\Filament\Admin\Resources\KitCheckouts\KitCheckoutResource;
use App\Filament\Admin\Resources\Kits\KitResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\Customer;
use App\Models\Kit;
use App\Models\Patient;
use App\Models\User;
use App\Services\Assets\KitService;
use App\Services\Clinical\PatientService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewKit extends ViewRecord
{
    protected static string $resource = KitResource::class;

    public function getTitle(): string
    {
        return __('Kit :code', ['code' => $this->kit()->code]);
    }

    protected function getHeaderActions(): array
    {
        $user = ServiceAction::user();
        $available = fn () => $this->kit()->status === KitStatus::Available;

        return [
            Action::make('scan')
                ->label(__('Scan into kit'))
                ->icon(Heroicon::OutlinedQrCode)
                ->visible(fn () => $available() && $user->canInBranch('kits.prepare', $this->kit()->branch_id))
                ->modalDescription(__('Scan instruments, serial implants or lot boxes (1 per scan). They come from this branch\'s shelf.'))
                ->modalSubmitActionLabel(__('Add'))
                ->schema([TextInput::make('code')->label(__('Code'))->required()->autofocus()
                    ->extraInputAttributes(['dir' => 'ltr', 'class' => 'ot-code', 'data-scan-field' => true])])
                ->action(function (array $data, Action $action) {
                    $added = ServiceAction::run($action, fn ($u) => app(KitService::class)->addByScan($this->kit(), $data['code'], $u));

                    if ($added !== null) {
                        Notification::make()->title(__(':code added to the kit.', ['code' => $added['code']]))->success()->send();
                        $this->refreshKit();
                        $this->replaceMountedAction('scan');
                    }
                }),
            Action::make('prepared')
                ->label(__('Mark as prepared'))
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->color('gray')
                ->visible(fn () => $available() && $user->canInBranch('kits.prepare', $this->kit()->branch_id))
                ->requiresConfirmation()
                ->modalDescription(fn () => app(KitService::class)->compare($this->kit())['complete'] ? null : __('The kit is not complete — refill it from the branch shelf.'))
                ->action(function (Action $action) {
                    ServiceAction::run($action, fn ($u) => app(KitService::class)->markPrepared($this->kit(), $u), __('Kit marked as prepared.'));
                    $this->refreshKit();
                }),
            Action::make('checkout')
                ->label(__('Check out to hospital'))
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('warning')
                ->visible(fn () => $available() && $user->canInBranch('kits.checkout', $this->kit()->branch_id))
                ->schema([
                    Select::make('customer_id')->label(__('Hospital'))->options(fn () => Customer::options())->searchable()->required()->native(false),
                    Select::make('doctor_id')->label(__('Doctor'))->options(fn () => User::doctorOptions())->native(false),
                    DatePicker::make('planned_surgery_date')->label(__('Planned surgery date'))->native(false)->displayFormat('Y-m-d'),
                    Select::make('patient_id')->label(__('Patient (optional)'))->searchable()->native(false)
                        ->getSearchResultsUsing(fn (string $search) => app(PatientService::class)->search(ServiceAction::user(), $search)->limit(30)->get()->mapWithKeys(fn (Patient $p) => [$p->id => $p->label()]))
                        ->getOptionLabelUsing(fn ($value) => Patient::query()->find($value)?->label()),
                    Textarea::make('notes')->label(__('Notes'))->rows(2),
                ])
                ->action(function (array $data, Action $action) {
                    $checkout = ServiceAction::run($action, fn ($u) => app(KitService::class)->checkout(
                        $this->kit(),
                        Customer::query()->findOrFail($data['customer_id']),
                        $u,
                        filled($data['doctor_id'] ?? null) ? User::query()->find($data['doctor_id']) : null,
                        filled($data['patient_id'] ?? null) ? Patient::query()->find($data['patient_id']) : null,
                        filled($data['planned_surgery_date'] ?? null) ? substr((string) $data['planned_surgery_date'], 0, 10) : null,
                        $data['notes'] ?? null,
                    ), __('Kit checked out.'));

                    if ($checkout !== null) {
                        $this->redirect(KitCheckoutResource::getUrl('view', ['record' => $checkout]));
                    }
                }),
            Action::make('open_checkout')
                ->label(__('Return the kit'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('success')
                ->visible(fn () => $this->kit()->status === KitStatus::OutAtHospital && $this->kit()->current_checkout_id !== null)
                ->url(fn () => KitCheckoutResource::getUrl('view', ['record' => $this->kit()->current_checkout_id])),
        ];
    }

    public function refreshKit(): void
    {
        $this->record = $this->kit()->fresh();
        $this->dispatch('kit-updated');
    }

    private function kit(): Kit
    {
        /** @var Kit */
        return $this->getRecord();
    }
}
