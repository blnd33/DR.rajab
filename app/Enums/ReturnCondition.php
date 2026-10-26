<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Condition of a returned item.
 */
enum ReturnCondition: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Good = 'good';
    case Damaged = 'damaged';

    public function getLabel(): string
    {
        return match ($this) {
            self::Good => __('Good — back to stock'),
            self::Damaged => __('Damaged — write off'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Good => 'success',
            self::Damaged => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Good => 'heroicon-m-check-circle',
            self::Damaged => 'heroicon-m-exclamation-triangle',
        };
    }
}
