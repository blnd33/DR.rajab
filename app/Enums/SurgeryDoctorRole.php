<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Role of a doctor in a surgery.
 */
enum SurgeryDoctorRole: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Lead = 'lead';
    case Assistant = 'assistant';
    case Technician = 'technician';

    public function getLabel(): string
    {
        return match ($this) {
            self::Lead => __('Lead surgeon'),
            self::Assistant => __('Assistant'),
            self::Technician => __('Technician'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Lead => 'primary',
            self::Assistant => 'gray',
            self::Technician => 'info',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Lead => 'heroicon-m-star',
            self::Assistant => 'heroicon-m-user',
            self::Technician => 'heroicon-m-wrench-screwdriver',
        };
    }
}
