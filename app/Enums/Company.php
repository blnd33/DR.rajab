<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasLabel;

/**
 * The companies the admin can switch between (top bar). KurdishMedicus is the main company: all
 * stations belong to it. Another company sees only its own stations — none yet, so its Main page
 * shows zero everywhere.
 */
enum Company: string implements HasLabel
{
    use EnumHelpers;

    case KurdishMedicus = 'kurdmedicus';
    case AlFadhaa = 'al_fadhaa';

    public static function main(): self
    {
        return self::KurdishMedicus;
    }

    public function isMain(): bool
    {
        return $this === self::main();
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::KurdishMedicus => __('KurdishMedicus'),
            self::AlFadhaa => __('Al-Fadhaa\' Bureau'),
        };
    }
}
