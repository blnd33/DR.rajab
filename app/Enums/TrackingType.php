<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Serial = one record per physical unit (implants). Lot = quantities per lot (screws, cement…).
 */
enum TrackingType: string implements HasIcon, HasLabel
{
    use EnumHelpers;

    case Serial = 'serial';
    case Lot = 'lot';

    public function getLabel(): string
    {
        return match ($this) {
            self::Serial => __('Serial number'),
            self::Lot => __('Lot + quantity'),
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Serial => 'heroicon-m-finger-print',
            self::Lot => 'heroicon-m-square-3-stack-3d',
        };
    }
}
