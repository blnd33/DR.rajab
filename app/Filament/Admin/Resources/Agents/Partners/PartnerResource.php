<?php

namespace App\Filament\Admin\Resources\Agents\Partners;

use App\Enums\AgentType;
use App\Filament\Admin\Resources\Agents\AgentResource;
use App\Filament\Admin\Resources\Agents\Partners\Pages\ListPartners;
use App\Filament\Admin\Resources\Agents\Partners\Pages\ViewPartner;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class PartnerResource extends AgentResource
{
    protected static ?string $slug = 'agents/partners';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 40;

    public static function agentType(): AgentType
    {
        return AgentType::Partner;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPartners::route('/'),
            'view' => ViewPartner::route('/{record}'),
        ];
    }
}
