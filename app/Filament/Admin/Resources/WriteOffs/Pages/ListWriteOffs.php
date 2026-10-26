<?php

namespace App\Filament\Admin\Resources\WriteOffs\Pages;

use App\Filament\Admin\Resources\WriteOffs\WriteOffResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWriteOffs extends ListRecords
{
    protected static string $resource = WriteOffResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('Report a write-off'))];
    }
}
