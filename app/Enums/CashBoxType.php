<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Kind of money box.
 */
enum CashBoxType: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Cash = 'cash';
    case Bank = 'bank';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cash => __('Cash box'),
            self::Bank => __('Bank account'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Cash => 'success',
            self::Bank => 'info',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Cash => 'heroicon-m-banknotes',
            self::Bank => 'heroicon-m-building-library',
        };
    }
}
