<?php

namespace App\Filament\Admin\Resources\KitTypes\Pages;

use App\Filament\Admin\Resources\KitTypes\KitTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateKitType extends CreateRecord
{
    protected static string $resource = KitTypeResource::class;

    protected static bool $canCreateAnother = false;

    protected function getRedirectUrl(): string
    {
        // Straight to the template.
        return KitTypeResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
