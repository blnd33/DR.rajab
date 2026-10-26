<?php

namespace App\Filament\Admin\Resources\Surgeries\Pages;

use App\Enums\SurgeryStatus;
use App\Filament\Admin\Resources\Surgeries\SurgeryResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\Surgery;
use App\Services\Clinical\SurgeryService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ViewSurgery extends ViewRecord
{
    protected static string $resource = SurgeryResource::class;

    public function getTitle(): string
    {
        return __('Surgery :number', ['number' => $this->surgery()->number]);
    }

    protected function getHeaderActions(): array
    {
        $user = ServiceAction::user();

        return [
            Action::make('complete')
                ->label(__('Complete surgery'))
                ->icon(Heroicon::OutlinedCheck)
                ->color('success')
                ->visible(fn () => $this->surgery()->status === SurgeryStatus::Draft && $user->canInBranch('surgeries.create', $this->surgery()->branch_id))
                ->modalDescription(__('Takes every item out of stock now.'))
                ->schema([TextInput::make('override_reason')->label(__('Reason to use expired items (only if there are any)'))->maxLength(500)])
                ->action(function (array $data, Action $action) {
                    ServiceAction::run($action, fn ($u) => app(SurgeryService::class)->complete($this->surgery(), $u, $data['override_reason'] ?? null), __('Surgery completed.'));
                    $this->refreshSurgery();
                }),
            Action::make('photos')
                ->label(__('Add photos'))
                ->icon(Heroicon::OutlinedCamera)
                ->color('gray')
                ->visible(fn () => $this->surgery()->status !== SurgeryStatus::Cancelled && $user->canInBranch('surgeries.create', $this->surgery()->branch_id))
                ->schema([FileUpload::make('photos')->label(__('Photos of the implant stickers'))->image()->multiple()->disk('local')->directory('tmp/surgeries')->maxSize(8192)->required()])
                ->action(function (array $data, Action $action) {
                    ServiceAction::run($action, function ($u) use ($data) {
                        foreach ((array) $data['photos'] as $path) {
                            $file = new UploadedFile(Storage::disk('local')->path($path), basename($path), Storage::disk('local')->mimeType($path) ?: null, null, true);
                            app(SurgeryService::class)->attachPhoto($this->surgery(), $file, $u);
                        }
                    }, __('Photos added.'));
                    $this->refreshSurgery();
                }),
            Action::make('cancel')
                ->label(__('Cancel surgery'))
                ->icon(Heroicon::OutlinedXMark)
                ->color('danger')
                ->visible(fn () => $this->surgery()->status !== SurgeryStatus::Cancelled
                    && ($user->canInBranch('surgeries.cancel', $this->surgery()->branch_id) || ($this->surgery()->status === SurgeryStatus::Draft && $this->surgery()->created_by === $user->id)))
                ->modalDescription(fn () => $this->surgery()->status === SurgeryStatus::Completed ? __('Every item goes back on the shelf of the branch.') : null)
                ->schema([Textarea::make('reason')->label(__('Reason'))->required()->rows(2)])
                ->action(function (array $data, Action $action) {
                    ServiceAction::run($action, fn ($u) => app(SurgeryService::class)->cancel($this->surgery(), $data['reason'], $u), __('Surgery cancelled.'));
                    $this->refreshSurgery();
                }),
        ];
    }

    public function refreshSurgery(): void
    {
        $this->record = $this->surgery()->fresh();
        $this->dispatch('surgery-updated');
    }

    private function surgery(): Surgery
    {
        /** @var Surgery */
        return $this->getRecord();
    }
}
