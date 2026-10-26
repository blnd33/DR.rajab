<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Urgency of a stock request.
 */
enum RequestPriority: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Normal = 'normal';
    case Urgent = 'urgent';

    public function getLabel(): string
    {
        return match ($this) {
            self::Normal => __('Normal'),
            self::Urgent => __('Urgent'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Normal => 'gray',
            self::Urgent => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Normal => 'heroicon-m-minus',
            self::Urgent => 'heroicon-m-bolt',
        };
    }
}
