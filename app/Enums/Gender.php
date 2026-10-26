<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Patient gender (optional).
 */
enum Gender: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Male = 'male';
    case Female = 'female';

    public function getLabel(): string
    {
        return match ($this) {
            self::Male => __('Male'),
            self::Female => __('Female'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Male => 'gray',
            self::Female => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Male => 'heroicon-m-user',
            self::Female => 'heroicon-m-user',
        };
    }
}
