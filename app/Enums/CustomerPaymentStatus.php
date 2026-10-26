<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Customer payment.
 */
enum CustomerPaymentStatus: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Posted = 'posted';
    case Voided = 'voided';

    public function getLabel(): string
    {
        return match ($this) {
            self::Posted => __('Received'),
            self::Voided => __('Voided'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Posted => 'success',
            self::Voided => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Posted => 'heroicon-m-check-circle',
            self::Voided => 'heroicon-m-x-mark',
        };
    }
}
