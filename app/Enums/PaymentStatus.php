<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * How much of an invoice is paid.
 */
enum PaymentStatus: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Unpaid = 'unpaid';
    case Partial = 'partial';
    case Paid = 'paid';

    public function getLabel(): string
    {
        return match ($this) {
            self::Unpaid => __('Unpaid'),
            self::Partial => __('Partly paid'),
            self::Paid => __('Paid'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Unpaid => 'danger',
            self::Partial => 'warning',
            self::Paid => 'success',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Unpaid => 'heroicon-m-clock',
            self::Partial => 'heroicon-m-minus-circle',
            self::Paid => 'heroicon-m-check-circle',
        };
    }
}
