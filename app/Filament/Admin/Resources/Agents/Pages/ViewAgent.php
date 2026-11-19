<?php

namespace App\Filament\Admin\Resources\Agents\Pages;

use App\Filament\Admin\Resources\Agents\AgentResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * One company agent: their information and CV.
 */
abstract class ViewAgent extends ViewRecord
{
    protected function getHeaderActions(): array
    {
        /** @var class-string<AgentResource> $resource */
        $resource = static::getResource();

        return [$resource::editAction()->record($this->getRecord())];
    }
}
