<?php

namespace App\Filament\Admin\Resources\StockUnits\Pages;

use App\Filament\Admin\Pages\ReceiveStock;
use App\Filament\Admin\Resources\StockUnits\StockUnitResource;
use App\Filament\Admin\Support\StockExcelActions;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListStockUnits extends ListRecords
{
    protected static string $resource = StockUnitResource::class;

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
