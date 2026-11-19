<?php

namespace App\Filament\Admin\Resources\Agents\DirectSellers;

use App\Enums\AgentType;
use App\Filament\Admin\Resources\Agents\AgentResource;
use App\Filament\Admin\Resources\Agents\DirectSellers\Pages\ListDirectSellers;
use App\Filament\Admin\Resources\Agents\DirectSellers\Pages\ViewDirectSeller;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class DirectSellerResource extends AgentResource
{
    protected static ?string $slug = 'agents/direct-sellers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?int $navigationSort = 20;

    public static function agentType(): AgentType
    {
        return AgentType::DirectSeller;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDirectSellers::route('/'),
            'view' => ViewDirectSeller::route('/{record}'),
        ];
    }
}
