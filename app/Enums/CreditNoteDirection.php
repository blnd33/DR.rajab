<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Who owes whom after a credit note.
 */
enum CreditNoteDirection: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case FromSupplier = 'from_supplier';
    case ToCustomer = 'to_customer';

    public function getLabel(): string
    {
        return match ($this) {
            self::FromSupplier => __('From supplier'),
            self::ToCustomer => __('To customer'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::FromSupplier => 'info',
            self::ToCustomer => 'info',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::FromSupplier => 'heroicon-m-arrow-down-left',
            self::ToCustomer => 'heroicon-m-arrow-up-right',
        };
    }
}
