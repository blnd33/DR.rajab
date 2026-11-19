<?php

namespace App\Filament\Admin\Resources\Drivers\Pages;

use App\Filament\Admin\Resources\Drivers\DriverResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Services\Agents\DriverService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListDrivers extends ListRecords
{
    protected static string $resource = DriverResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')->label(__('Add a driver'))->icon(Heroicon::OutlinedPlus)
                ->visible(fn () => DriverResource::canCreate())
                ->modalHeading(__('Add a driver'))
                ->schema(DriverResource::formFields())
                ->action(fn (array $data, Action $action) => ServiceAction::run($action,
                    fn ($u) => app(DriverService::class)->create(DriverResource::serviceData($data), $u), __('Saved.'))),
        ];
    }
}
