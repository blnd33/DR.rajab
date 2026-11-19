<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasLabel;

/**
 * The owner's companies: one system, separate data (App\Support\CompanyContext). KurdishMedicus is
 * the main company (main database); the others have their own database.
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
