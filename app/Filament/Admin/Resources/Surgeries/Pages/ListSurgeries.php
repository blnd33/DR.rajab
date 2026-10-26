<?php

namespace App\Filament\Admin\Resources\Surgeries\Pages;

use App\Filament\Admin\Resources\Surgeries\SurgeryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSurgeries extends ListRecords
{
    protected static string $resource = SurgeryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('Log a surgery'))];
    }
}
