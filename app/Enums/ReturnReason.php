<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Why a customer gave an item back.
 */
enum ReturnReason: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Unused = 'unused';
    case WrongSize = 'wrong_size';
    case CancelledSurgery = 'cancelled_surgery';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Unused => __('Not used'),
            self::WrongSize => __('Wrong size'),
            self::CancelledSurgery => __('Surgery cancelled'),
            self::Other => __('Other'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Unused => 'gray',
            self::WrongSize => 'warning',
            self::CancelledSurgery => 'info',
            self::Other => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Unused => 'heroicon-m-arrow-uturn-left',
            self::WrongSize => 'heroicon-m-arrows-pointing-out',
            self::CancelledSurgery => 'heroicon-m-calendar-days',
            self::Other => 'heroicon-m-ellipsis-horizontal',
        };
    }
}
