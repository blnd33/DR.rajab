<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Where an exchange rate came from.
 */
enum RateSource: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Manual = 'manual';
    case Ecb = 'ecb';
    case ApiOther = 'api_other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manual => __('Entered by hand'),
            self::Ecb => __('European Central Bank'),
            self::ApiOther => __('Other source'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Manual => 'gray',
            self::Ecb => 'info',
            self::ApiOther => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Manual => 'heroicon-m-pencil',
            self::Ecb => 'heroicon-m-globe-europe-africa',
            self::ApiOther => 'heroicon-m-globe-alt',
        };
    }
}
