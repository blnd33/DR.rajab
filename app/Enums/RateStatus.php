<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * State of an exchange rate entry.
 */
enum RateStatus: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Suggested = 'suggested';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::Suggested => __('Suggested'),
            self::Confirmed => __('Confirmed'),
            self::Rejected => __('Rejected'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Suggested => 'warning',
            self::Confirmed => 'success',
            self::Rejected => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Suggested => 'heroicon-m-light-bulb',
            self::Confirmed => 'heroicon-m-check-circle',
            self::Rejected => 'heroicon-m-x-circle',
        };
    }
}
