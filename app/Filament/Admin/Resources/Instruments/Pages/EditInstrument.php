<?php

namespace App\Filament\Admin\Resources\Instruments\Pages;

use App\Filament\Admin\Resources\Instruments\InstrumentResource;
use Filament\Resources\Pages\EditRecord;

/**
 * Descriptive fields only (maker serial, purchase, notes); status changes use the actions.
 */
class EditInstrument extends EditRecord
{
    protected static string $resource = InstrumentResource::class;

    protected function getRedirectUrl(): string
    {
        return InstrumentResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
