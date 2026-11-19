<?php

namespace App\Filament\Admin\Resources\Agents\Representatives;

use App\Enums\AgentType;
use App\Filament\Admin\Resources\Agents\AgentResource;
use App\Filament\Admin\Resources\Agents\Representatives\Pages\ListRepresentatives;
use App\Filament\Admin\Resources\Agents\Representatives\Pages\ViewRepresentative;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class RepresentativeResource extends AgentResource
{
    protected static ?string $slug = 'agents/representatives';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?int $navigationSort = 30;

    public static function agentType(): AgentType
    {
        return AgentType::Representative;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRepresentatives::route('/'),
            'view' => ViewRepresentative::route('/{record}'),
        ];
    }
}
