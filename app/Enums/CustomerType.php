<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Kind of customer (Module 11).
 */
enum CustomerType: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Hospital = 'hospital';
    case Clinic = 'clinic';
    case Distributor = 'distributor';
    case Individual = 'individual';

    public function getLabel(): string
    {
        return match ($this) {
            self::Hospital => __('Hospital'),
            self::Clinic => __('Clinic'),
            self::Distributor => __('Distributor'),
            self::Individual => __('Individual'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Hospital => 'primary',
            self::Clinic => 'info',
            self::Distributor => 'gray',
            self::Individual => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Hospital => 'heroicon-m-building-office-2',
            self::Clinic => 'heroicon-m-building-storefront',
            self::Distributor => 'heroicon-m-truck',
            self::Individual => 'heroicon-m-user',
        };
    }
}
