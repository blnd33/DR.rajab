<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * What a commission rule pays for.
 */
enum CommissionBasis: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case PerSurgery = 'per_surgery';
    case PerProduct = 'per_product';

    public function getLabel(): string
    {
        return match ($this) {
            self::PerSurgery => __('Per surgery'),
            self::PerProduct => __('Per product used'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::PerSurgery => 'gray',
            self::PerProduct => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::PerSurgery => 'heroicon-m-heart',
            self::PerProduct => 'heroicon-m-cube',
        };
    }
}
