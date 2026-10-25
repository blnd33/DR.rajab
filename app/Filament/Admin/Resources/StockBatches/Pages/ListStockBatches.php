<?php

namespace App\Filament\Admin\Resources\StockBatches\Pages;

use App\Filament\Admin\Pages\ReceiveStock;
use App\Filament\Admin\Resources\StockBatches\StockBatchResource;
use App\Filament\Admin\Support\StockExcelActions;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListStockBatches extends ListRecords
{
    protected static string $resource = StockBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            StockExcelActions::group(),
            Action::make('receive')
                ->label(__('Receive stock'))
                ->icon(Heroicon::OutlinedInboxArrowDown)
                ->url(ReceiveStock::getUrl())
                ->visible(fn () => ReceiveStock::canAccess()),
        ];
    }
}
