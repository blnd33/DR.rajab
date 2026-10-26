<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Commission entry.
 */
enum CommissionStatus: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Accrued = 'accrued';
    case Approved = 'approved';
    case Paid = 'paid';
    case Voided = 'voided';

    public function getLabel(): string
    {
        return match ($this) {
            self::Accrued => __('Earned'),
            self::Approved => __('Approved'),
            self::Paid => __('Paid'),
            self::Voided => __('Voided'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Accrued => 'warning',
            self::Approved => 'info',
            self::Paid => 'success',
            self::Voided => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Accrued => 'heroicon-m-clock',
            self::Approved => 'heroicon-m-check',
            self::Paid => 'heroicon-m-check-circle',
            self::Voided => 'heroicon-m-x-mark',
        };
    }
}
