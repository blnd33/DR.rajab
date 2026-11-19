<?php

namespace App\Filament\Admin\Resources\Drivers\Pages;

use App\Filament\Admin\Resources\Drivers\DriverResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * One driver: the car and plate, what we paid in total, and the trips (added on the Trips table).
 */
class ViewDriver extends ViewRecord
{
    protected static string $resource = DriverResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DriverResource::editAction()->record($this->getRecord()),
        ];
    }
}
