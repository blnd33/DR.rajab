<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * How a commission is calculated.
 */
enum CommissionCalc: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Fixed = 'fixed';
    case Percent = 'percent';

    public function getLabel(): string
    {
        return match ($this) {
            self::Fixed => __('Fixed amount'),
            self::Percent => __('Percent of value'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Fixed => 'gray',
            self::Percent => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Fixed => 'heroicon-m-currency-dollar',
            self::Percent => 'heroicon-m-receipt-percent',
        };
    }
}
