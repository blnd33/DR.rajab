<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Line on a supplier or customer statement.
 */
enum PartyEntryType: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Opening = 'opening';
    case Invoice = 'invoice';
    case Payment = 'payment';
    case CreditNote = 'credit_note';
    case Return = 'return';
    case LandedCost = 'landed_cost';
    case Adjustment = 'adjustment';
    case Reversal = 'reversal';

    public function getLabel(): string
    {
        return match ($this) {
            self::Opening => __('Opening balance'),
            self::Invoice => __('Invoice'),
            self::Payment => __('Payment'),
            self::CreditNote => __('Credit note'),
            self::Return => __('Return'),
            self::LandedCost => __('Landed cost'),
            self::Adjustment => __('Adjustment'),
            self::Reversal => __('Reversal'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Opening => 'gray',
            self::Invoice => 'warning',
            self::Payment => 'success',
            self::CreditNote => 'info',
            self::Return => 'info',
            self::LandedCost => 'warning',
            self::Adjustment => 'gray',
            self::Reversal => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Opening => 'heroicon-m-flag',
            self::Invoice => 'heroicon-m-document-text',
            self::Payment => 'heroicon-m-banknotes',
            self::CreditNote => 'heroicon-m-receipt-refund',
            self::Return => 'heroicon-m-arrow-uturn-left',
            self::LandedCost => 'heroicon-m-truck',
            self::Adjustment => 'heroicon-m-scale',
            self::Reversal => 'heroicon-m-arrow-uturn-left',
        };
    }
}
