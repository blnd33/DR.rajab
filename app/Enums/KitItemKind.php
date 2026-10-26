<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * What a kit template line expects.
 */
enum KitItemKind: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Instrument = 'instrument';
    case Product = 'product';

    public function getLabel(): string
    {
        return match ($this) {
            self::Instrument => __('Instrument'),
            self::Product => __('Implant / product'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Instrument => 'gray',
            self::Product => 'primary',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Instrument => 'heroicon-m-wrench',
            self::Product => 'heroicon-m-cube',
        };
    }
}
