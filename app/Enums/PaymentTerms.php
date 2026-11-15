<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Paid now, pay later (marked paid by hand), or on credit (customer payments, behind a switch).
 */
enum PaymentTerms: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Cash = 'cash';
    case Credit = 'credit';
    case Later = 'later';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cash => __('Paid now'),
            self::Credit => __('On credit'),
            self::Later => __('Pay later'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Cash => 'success',
            self::Credit => 'warning',
            self::Later => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Cash => 'heroicon-m-banknotes',
            self::Credit => 'heroicon-m-clock',
            self::Later => 'heroicon-m-document-text',
        };
    }
}
