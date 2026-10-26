<?php

namespace App\Filament\Admin\Resources\StockRequests\Pages;

use App\Filament\Admin\Resources\StockRequests\StockRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStockRequests extends ListRecords
{
    protected static string $resource = StockRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('New request'))];
    }
}
