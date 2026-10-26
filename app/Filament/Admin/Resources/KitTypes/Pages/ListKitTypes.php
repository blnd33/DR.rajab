<?php

namespace App\Filament\Admin\Resources\KitTypes\Pages;

use App\Filament\Admin\Resources\KitTypes\KitTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListKitTypes extends ListRecords
{
    protected static string $resource = KitTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
