<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasLabel;

/**
 * Iraqi number plates. English (the new plates): 2-digit code, a letter, a number — "24 A 3003".
 * Arabic (the older plates): a number and "Iraq" or the city — "12345 دهوك".
 */
enum PlateKind: string implements HasLabel
{
    use EnumHelpers;

    case English = 'english';
    case Arabic = 'arabic';

    public function getLabel(): string
    {
        return match ($this) {
            self::English => __('English plate (24 A 3003)'),
            self::Arabic => __('Arabic plate (number and city)'),
        };
    }
}
