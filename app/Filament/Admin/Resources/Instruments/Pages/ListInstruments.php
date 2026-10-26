<?php

namespace App\Filament\Admin\Resources\Instruments\Pages;

use App\Filament\Admin\Resources\Instruments\InstrumentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInstruments extends ListRecords
{
    protected static string $resource = InstrumentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
