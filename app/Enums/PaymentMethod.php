<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * How money was paid or received.
 */
enum PaymentMethod: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case BankWire = 'bank_wire';
    case Hawala = 'hawala';
    case Cash = 'cash';
    case Cheque = 'cheque';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::BankWire => __('Bank wire'),
            self::Hawala => __('Hawala'),
            self::Cash => __('Cash'),
            self::Cheque => __('Cheque'),
            self::Other => __('Other'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::BankWire => 'info',
            self::Hawala => 'warning',
            self::Cash => 'success',
            self::Cheque => 'gray',
            self::Other => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::BankWire => 'heroicon-m-building-library',
            self::Hawala => 'heroicon-m-arrows-right-left',
            self::Cash => 'heroicon-m-banknotes',
            self::Cheque => 'heroicon-m-document',
            self::Other => 'heroicon-m-ellipsis-horizontal',
        };
    }
}
