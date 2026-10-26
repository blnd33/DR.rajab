<?php

namespace App\Filament\Admin\Resources\CashBoxClosings\Pages;

use App\Filament\Admin\Resources\CashBoxClosings\CashBoxClosingResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\CashBox;
use App\Services\Money\CashBoxService;
use App\Services\Money\CashClosingService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageCashBoxClosings extends ManageRecords
{
    protected static string $resource = CashBoxClosingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('start')
                ->label(__('Close a box today'))
                ->icon(Heroicon::OutlinedScale)
                ->schema([Select::make('cash_box_id')->label(__('Box'))->required()->native(false)
                    ->options(fn () => app(CashBoxService::class)->options(ServiceAction::user(), 'cash_boxes.close_day'))])
                ->action(fn (array $data, Action $action) => ServiceAction::run($action, fn ($u) => app(CashClosingService::class)->start(CashBox::query()->findOrFail($data['cash_box_id']), $u), __('Closing started — count the drawer.'))),
        ];
    }
}
