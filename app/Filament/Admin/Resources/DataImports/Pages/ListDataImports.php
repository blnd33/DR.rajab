<?php

namespace App\Filament\Admin\Resources\DataImports\Pages;

use App\Filament\Admin\Resources\DataImports\DataImportResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListDataImports extends ListRecords
{
    protected static string $resource = DataImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('New import'))->icon(Heroicon::OutlinedArrowUpTray),
        ];
    }
}
