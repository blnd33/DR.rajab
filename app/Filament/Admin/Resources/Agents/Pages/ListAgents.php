<?php

namespace App\Filament\Admin\Resources\Agents\Pages;

use App\Filament\Admin\Resources\Agents\AgentForm;
use App\Filament\Admin\Resources\Agents\AgentResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Services\Agents\CompanyAgentService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

/**
 * The list of one kind of company agent, with "Add …".
 */
abstract class ListAgents extends ListRecords
{
    protected function getHeaderActions(): array
    {
        /** @var class-string<AgentResource> $resource */
        $resource = static::getResource();
        $type = $resource::agentType();

        return [
            Action::make('create')->label(__('Add :agent', ['agent' => $type->getLabel()]))->icon(Heroicon::OutlinedPlus)
                ->visible(fn () => $resource::canCreate())
                ->modalHeading(__('Add :agent', ['agent' => $type->getLabel()]))
                ->schema(AgentForm::components($type))
                ->action(function (array $data, Action $action) use ($type) {
                    ServiceAction::run($action, fn ($u) => app(CompanyAgentService::class)->create($type, $data, $u, AgentForm::cv($data)), __('Saved.'));
                    AgentForm::forgetUpload($data);
                }),
        ];
    }
}
