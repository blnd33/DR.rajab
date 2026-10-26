<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Billing hook for surgeries (open question 4).
 */
enum BillingStatus: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case NotBilled = 'not_billed';
    case Billed = 'billed';
    case Waived = 'waived';

    public function getLabel(): string
    {
        return match ($this) {
            self::NotBilled => __('Not billed'),
            self::Billed => __('Billed'),
            self::Waived => __('Waived'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::NotBilled => 'gray',
            self::Billed => 'success',
            self::Waived => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::NotBilled => 'heroicon-m-minus',
            self::Billed => 'heroicon-m-document-text',
            self::Waived => 'heroicon-m-hand-raised',
        };
    }
}
