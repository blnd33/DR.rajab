<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * What an invoice is for: items sold, or a surgery's fee.
 */
enum InvoiceKind: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Sale = 'sale';
    case Surgery = 'surgery';

    public function getLabel(): string
    {
        return match ($this) {
            self::Sale => __('Sale'),
            self::Surgery => __('Surgery'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Sale => 'info',
            self::Surgery => 'primary',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Sale => 'heroicon-m-shopping-bag',
            self::Surgery => 'heroicon-m-heart',
        };
    }
}
