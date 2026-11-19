<?php

namespace App\Filament\Admin\Resources\Agents\Distributors;

use App\Enums\AgentType;
use App\Filament\Admin\Resources\Agents\AgentResource;
use App\Filament\Admin\Resources\Agents\Distributors\Pages\ListDistributors;
use App\Filament\Admin\Resources\Agents\Distributors\Pages\ViewDistributor;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class DistributorResource extends AgentResource
{
    protected static ?string $slug = 'agents/distributors';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?int $navigationSort = 10;

    public static function agentType(): AgentType
    {
        return AgentType::Distributor;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDistributors::route('/'),
            'view' => ViewDistributor::route('/{record}'),
        ];
    }
}
