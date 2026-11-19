<?php

namespace App\Filament\Admin\Resources\Technicians\Pages;

use App\Filament\Admin\Resources\Technicians\TechnicianResource;
use App\Filament\Admin\Support\ServiceAction;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListTechnicians extends ListRecords
{
    protected static string $resource = TechnicianResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')->label(__('Add a technician'))->icon(Heroicon::OutlinedPlus)
                ->visible(fn () => TechnicianResource::canCreate())
                ->schema(TechnicianResource::formFields())
                ->action(fn (array $data, Action $action) => ServiceAction::run($action, fn ($u) => TechnicianResource::save(null, $data, $u), __('Technician added.'))),
        ];
    }
}
