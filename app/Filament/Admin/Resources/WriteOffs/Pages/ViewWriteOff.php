<?php

namespace App\Filament\Admin\Resources\WriteOffs\Pages;

use App\Enums\WriteOffStatus;
use App\Filament\Admin\Resources\WriteOffs\WriteOffResource;
use App\Filament\Admin\Support\RefreshesInfolist;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\WriteOff;
use App\Services\Movement\WriteOffService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewWriteOff extends ViewRecord
{
    use RefreshesInfolist;

    protected static string $resource = WriteOffResource::class;

    public function getTitle(): string
    {
        return __('Write-off :number', ['number' => $this->writeOff()->number]);
    }

    protected function getHeaderActions(): array
    {
        $canDecide = fn () => $this->writeOff()->status === WriteOffStatus::Pending
            && ServiceAction::user()->canInBranch('write_offs.approve', $this->writeOff()->branch_id);

        return [
            Action::make('approve')
                ->label(__('Approve'))
                ->icon(Heroicon::OutlinedCheck)
                ->color('success')
                ->visible($canDecide)
                ->requiresConfirmation()
                ->modalDescription(__('The items leave stock now and their cost is recorded as a loss.'))
                ->action(function (Action $action) {
                    ServiceAction::run($action, fn ($u) => app(WriteOffService::class)->approve($this->writeOff(), $u), __('Write-off approved.'));
                    $this->record = $this->writeOff()->fresh();
                }),
            Action::make('reject')
                ->label(__('Reject'))
                ->icon(Heroicon::OutlinedXMark)
                ->color('danger')
                ->visible($canDecide)
                ->schema([Textarea::make('reason')->label(__('Reason'))->required()->rows(2)])
                ->action(function (array $data, Action $action) {
                    ServiceAction::run($action, fn ($u) => app(WriteOffService::class)->reject($this->writeOff(), $data['reason'], $u), __('Write-off rejected.'));
                    $this->record = $this->writeOff()->fresh();
                }),
        ];
    }

    private function writeOff(): WriteOff
    {
        /** @var WriteOff */
        return $this->getRecord();
    }
}
