<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Why stock is written off (Module 15).
 */
enum WriteOffReason: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Damaged = 'damaged';
    case Lost = 'lost';
    case Expired = 'expired';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Damaged => __('Damaged'),
            self::Lost => __('Lost'),
            self::Expired => __('Expired'),
            self::Other => __('Other'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Damaged => 'danger',
            self::Lost => 'danger',
            self::Expired => 'danger',
            self::Other => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Damaged => 'heroicon-m-exclamation-triangle',
            self::Lost => 'heroicon-m-question-mark-circle',
            self::Expired => 'heroicon-m-no-symbol',
            self::Other => 'heroicon-m-ellipsis-horizontal',
        };
    }
}
