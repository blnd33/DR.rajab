<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Kinds of issues the Super Admin resolves.
 */
enum IssueType: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case UnaccountedImplant = 'unaccounted_implant';
    case TransferDiscrepancy = 'transfer_discrepancy';
    case CountDiscrepancy = 'count_discrepancy';
    case CashDifference = 'cash_difference';
    case ExpiredOverride = 'expired_override';
    case UnknownScan = 'unknown_scan';

    public function getLabel(): string
    {
        return match ($this) {
            self::UnaccountedImplant => __('Unaccounted implant'),
            self::TransferDiscrepancy => __('Transfer difference'),
            self::CountDiscrepancy => __('Stock count difference'),
            self::CashDifference => __('Cash difference'),
            self::ExpiredOverride => __('Expired item used (override)'),
            self::UnknownScan => __('Unknown code scanned'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::UnaccountedImplant => 'danger',
            self::TransferDiscrepancy => 'warning',
            self::CountDiscrepancy => 'warning',
            self::CashDifference => 'danger',
            self::ExpiredOverride => 'danger',
            self::UnknownScan => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::UnaccountedImplant => 'heroicon-m-heart',
            self::TransferDiscrepancy => 'heroicon-m-truck',
            self::CountDiscrepancy => 'heroicon-m-clipboard-document-check',
            self::CashDifference => 'heroicon-m-banknotes',
            self::ExpiredOverride => 'heroicon-m-no-symbol',
            self::UnknownScan => 'ot-barcode',
        };
    }
}
