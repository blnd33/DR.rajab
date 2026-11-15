<?php

namespace App\Filament\Admin\Resources\Technicians\Pages;

use App\Filament\Admin\Pages\ViewGraph;
use App\Filament\Admin\Resources\Technicians\TechnicianResource;
use App\Services\Graphs\GraphRegistry;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewTechnician extends ViewRecord
{
    protected static string $resource = TechnicianResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('graphs')->label(__('Technician graphs'))->icon(Heroicon::OutlinedChartBar)->color('gray')
                ->visible(fn () => GraphRegistry::canUse(auth()->user()))
                ->url(fn () => ViewGraph::getUrl(['graph' => 'surgeries-by-technician'])),
        ];
    }
}
