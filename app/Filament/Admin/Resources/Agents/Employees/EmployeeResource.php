<?php

namespace App\Filament\Admin\Resources\Agents\Employees;

use App\Enums\AgentType;
use App\Filament\Admin\Resources\Agents\AgentResource;
use App\Filament\Admin\Resources\Agents\Employees\Pages\ListEmployees;
use App\Filament\Admin\Resources\Agents\Employees\Pages\ViewEmployee;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class EmployeeResource extends AgentResource
{
    protected static ?string $slug = 'agents/employees';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?int $navigationSort = 60;

    public static function agentType(): AgentType
    {
        return AgentType::Employee;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployees::route('/'),
            'view' => ViewEmployee::route('/{record}'),
        ];
    }
}
