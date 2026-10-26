<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Where an item used in surgery came from.
 */
enum SurgeryItemSource: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Shelf = 'shelf';
    case Kit = 'kit';

    public function getLabel(): string
    {
        return match ($this) {
            self::Shelf => __('Branch shelf'),
            self::Kit => __('Kit'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Shelf => 'gray',
            self::Kit => 'info',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Shelf => 'heroicon-m-archive-box',
            self::Kit => 'heroicon-m-briefcase',
        };
    }
}
