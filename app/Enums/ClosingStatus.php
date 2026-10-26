<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Daily cash closing.
 */
enum ClosingStatus: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case Reviewed = 'reviewed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Submitted => __('Submitted'),
            self::Reviewed => __('Reviewed'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Submitted => 'warning',
            self::Reviewed => 'success',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Draft => 'heroicon-m-pencil-square',
            self::Submitted => 'heroicon-m-lock-closed',
            self::Reviewed => 'heroicon-m-check-badge',
        };
    }
}
