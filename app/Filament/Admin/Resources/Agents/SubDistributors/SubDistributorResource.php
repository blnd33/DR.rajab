<?php

namespace App\Filament\Admin\Resources\Agents\SubDistributors;

use App\Enums\AgentType;
use App\Filament\Admin\Resources\Agents\AgentResource;
use App\Filament\Admin\Resources\Agents\SubDistributors\Pages\ListSubDistributors;
use App\Filament\Admin\Resources\Agents\SubDistributors\Pages\ViewSubDistributor;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/**
 * Company agents → Sub distributors: each works under a distributor.
 */
class SubDistributorResource extends AgentResource
{
    protected static ?string $slug = 'agents/sub-distributors';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    // Right after Distributors.
    protected static ?int $navigationSort = 15;

    public static function agentType(): AgentType
    {
        return AgentType::SubDistributor;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubDistributors::route('/'),
            'view' => ViewSubDistributor::route('/{record}'),
        ];
    }
}
