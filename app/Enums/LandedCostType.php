<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Kind of landed cost.
 */
enum LandedCostType: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Shipping = 'shipping';
    case Customs = 'customs';
    case Clearance = 'clearance';
    case BankFee = 'bank_fee';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Shipping => __('Shipping'),
            self::Customs => __('Customs'),
            self::Clearance => __('Clearance'),
            self::BankFee => __('Bank fee'),
            self::Other => __('Other'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Shipping => 'gray',
            self::Customs => 'gray',
            self::Clearance => 'gray',
            self::BankFee => 'gray',
            self::Other => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Shipping => 'heroicon-m-truck',
            self::Customs => 'heroicon-m-building-library',
            self::Clearance => 'heroicon-m-clipboard-document-check',
            self::BankFee => 'heroicon-m-banknotes',
            self::Other => 'heroicon-m-ellipsis-horizontal',
        };
    }
}
