<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasLabel;

enum DigitSystem: string implements HasLabel
{
    use EnumHelpers;

    /** 0123456789 */
    case Latin = 'latn';

    /** ٠١٢٣٤٥٦٧٨٩ (Arabic-Indic, U+0660–U+0669) */
    case ArabicIndic = 'arab';

    public function getLabel(): string
    {
        return match ($this) {
            self::Latin => __('Western (123)'),
            self::ArabicIndic => __('Arabic-Indic (١٢٣)'),
        };
    }
}
