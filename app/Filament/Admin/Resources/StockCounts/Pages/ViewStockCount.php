<?php

namespace App\Filament\Admin\Resources\StockCounts\Pages;

use App\Enums\StockCountStatus;
use App\Filament\Admin\Resources\StockCounts\StockCountResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\StockCount;
use App\Services\Movement\StockCountService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewStockCount extends ViewRecord
{
    protected static string $resource = StockCountResource::class;

    public function getTitle(): string
    {
        return __('Stock count :number', ['number' => $this->stockCount()->number]);
    }

    protected function getHeaderActions(): array
    {
        $user = ServiceAction::user();

        return [
            Action::make('scan')
                ->label(__('Scan on the phone'))
                ->icon(Heroicon::OutlinedDevicePhoneMobile)
                ->color('gray')
                ->visible(fn () => $this->stockCount()->status === StockCountStatus::InProgress)
                ->url(fn () => route('ops.counts.show', $this->stockCount())),
            Action::make('submit')
                ->label(__('Submit for review'))
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->visible(fn () => $this->stockCount()->status === StockCountStatus::InProgress && $user->canInBranch('stock_counts.scan', $this->stockCount()->branch_id))
                ->requiresConfirmation()
                ->modalDescription(__('Items not counted are reported as missing.'))
                ->action(function (Action $action) {
                    ServiceAction::run($action, fn ($u) => app(StockCountService::class)->submit($this->stockCount(), $u), __('Count submitted.'));
                    $this->refreshCount();
                }),
            Action::make('close')
                ->label(__('Close count'))
                ->icon(Heroicon::OutlinedCheck)
                ->color('success')
                ->visible(fn () => in_array($this->stockCount()->status, [StockCountStatus::Submitted, StockCountStatus::Reviewed], true) && $user->canInBranch('stock_counts.review', $this->stockCount()->branch_id))
                ->requiresConfirmation()
                ->action(function (Action $action) {
                    ServiceAction::run($action, fn ($u) => app(StockCountService::class)->close($this->stockCount(), $u), __('Count closed.'));
                    $this->refreshCount();
                }),
            Action::make('cancel')
                ->label(__('Cancel count'))
                ->icon(Heroicon::OutlinedXMark)
                ->color('danger')
                ->visible(fn () => $this->stockCount()->status === StockCountStatus::InProgress && $user->canInBranch('stock_counts.start', $this->stockCount()->branch_id))
                ->requiresConfirmation()
                ->action(function (Action $action) {
                    ServiceAction::run($action, fn ($u) => app(StockCountService::class)->cancel($this->stockCount(), $u), __('Count cancelled.'));
                    $this->refreshCount();
                }),
        ];
    }

    public function refreshCount(): void
    {
        $this->record = $this->stockCount()->fresh();
        $this->dispatch('count-updated');
    }

    private function stockCount(): StockCount
    {
        /** @var StockCount */
        return $this->getRecord();
    }
}
