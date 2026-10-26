<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Side of the body operated on (knee / hip).
 */
enum Laterality: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Left = 'left';
    case Right = 'right';
    case Bilateral = 'bilateral';
    case NotApplicable = 'na';

    public function getLabel(): string
    {
        return match ($this) {
            self::Left => __('Left'),
            self::Right => __('Right'),
            self::Bilateral => __('Both sides'),
            self::NotApplicable => __('Not applicable'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Left => 'gray',
            self::Right => 'gray',
            self::Bilateral => 'gray',
            self::NotApplicable => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Left => 'heroicon-m-arrow-left',
            self::Right => 'heroicon-m-arrow-right',
            self::Bilateral => 'heroicon-m-arrows-right-left',
            self::NotApplicable => 'heroicon-m-minus',
        };
    }
}
