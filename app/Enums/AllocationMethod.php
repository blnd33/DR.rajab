<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * How a landed cost is spread over the lines.
 */
enum AllocationMethod: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Value = 'value';
    case Quantity = 'quantity';

    public function getLabel(): string
    {
        return match ($this) {
            self::Value => __('By value'),
            self::Quantity => __('By quantity'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Value => 'gray',
            self::Quantity => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Value => 'heroicon-m-currency-dollar',
            self::Quantity => 'heroicon-m-hashtag',
        };
    }
}
