<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Landed cost.
 */
enum LandedCostStatus: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Allocated = 'allocated';
    case Voided = 'voided';

    public function getLabel(): string
    {
        return match ($this) {
            self::Allocated => __('Added to the cost'),
            self::Voided => __('Voided'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Allocated => 'success',
            self::Voided => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Allocated => 'heroicon-m-check-circle',
            self::Voided => 'heroicon-m-x-circle',
        };
    }
}
