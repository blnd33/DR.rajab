<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Cash or credit sale.
 */
enum PaymentTerms: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Cash = 'cash';
    case Credit = 'credit';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cash => __('Paid now'),
            self::Credit => __('On credit'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Cash => 'success',
            self::Credit => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Cash => 'heroicon-m-banknotes',
            self::Credit => 'heroicon-m-clock',
        };
    }
}
