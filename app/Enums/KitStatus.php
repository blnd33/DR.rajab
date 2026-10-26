<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a physical kit is (Module 18).
 */
enum KitStatus: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Available = 'available';
    case InTransit = 'in_transit';
    case OutAtHospital = 'out_at_hospital';
    case InMaintenance = 'in_maintenance';
    case Missing = 'missing';
    case Retired = 'retired';

    public function getLabel(): string
    {
        return match ($this) {
            self::Available => __('Available'),
            self::InTransit => __('In transit'),
            self::OutAtHospital => __('Out at hospital'),
            self::InMaintenance => __('In maintenance'),
            self::Missing => __('Missing'),
            self::Retired => __('Retired'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Available => 'success',
            self::InTransit => 'info',
            self::OutAtHospital => 'warning',
            self::InMaintenance => 'gray',
            self::Missing => 'danger',
            self::Retired => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Available => 'heroicon-m-check-circle',
            self::InTransit => 'heroicon-m-truck',
            self::OutAtHospital => 'heroicon-m-building-office-2',
            self::InMaintenance => 'heroicon-m-wrench-screwdriver',
            self::Missing => 'heroicon-m-question-mark-circle',
            self::Retired => 'heroicon-m-archive-box-x-mark',
        };
    }
}
