<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * What was done about a count difference.
 */
enum CountLineResolution: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Pending = 'pending';
    case Adjusted = 'adjusted';
    case WrittenOff = 'written_off';
    case Investigating = 'investigating';
    case Ignored = 'ignored';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => __('To decide'),
            self::Adjusted => __('Stock adjusted'),
            self::WrittenOff => __('Written off'),
            self::Investigating => __('Investigating'),
            self::Ignored => __('No action'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Adjusted => 'success',
            self::WrittenOff => 'danger',
            self::Investigating => 'info',
            self::Ignored => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Pending => 'heroicon-m-clock',
            self::Adjusted => 'heroicon-m-check-circle',
            self::WrittenOff => 'heroicon-m-trash',
            self::Investigating => 'heroicon-m-magnifying-glass',
            self::Ignored => 'heroicon-m-minus',
        };
    }
}
