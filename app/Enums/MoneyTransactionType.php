<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * What moved money in or out of a box (money ledger).
 */
enum MoneyTransactionType: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case OpeningBalance = 'opening_balance';
    case SaleReceipt = 'sale_receipt';
    case CustomerPayment = 'customer_payment';
    case SupplierPayment = 'supplier_payment';
    case Expense = 'expense';
    case ExchangeOut = 'exchange_out';
    case ExchangeIn = 'exchange_in';
    case BoxTransferOut = 'box_transfer_out';
    case BoxTransferIn = 'box_transfer_in';
    case Refund = 'refund';
    case ClosingAdjustment = 'closing_adjustment';
    case CommissionPayout = 'commission_payout';
    case Reversal = 'reversal';

    public function getLabel(): string
    {
        return match ($this) {
            self::OpeningBalance => __('Opening balance'),
            self::SaleReceipt => __('Sale'),
            self::CustomerPayment => __('Customer payment'),
            self::SupplierPayment => __('Supplier payment'),
            self::Expense => __('Expense'),
            self::ExchangeOut => __('Exchanged out'),
            self::ExchangeIn => __('Exchanged in'),
            self::BoxTransferOut => __('Sent to another box'),
            self::BoxTransferIn => __('Received from another box'),
            self::Refund => __('Refund'),
            self::ClosingAdjustment => __('Closing difference'),
            self::CommissionPayout => __('Commission paid'),
            self::Reversal => __('Reversal'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::OpeningBalance => 'gray',
            self::SaleReceipt => 'success',
            self::CustomerPayment => 'success',
            self::SupplierPayment => 'danger',
            self::Expense => 'danger',
            self::ExchangeOut => 'warning',
            self::ExchangeIn => 'warning',
            self::BoxTransferOut => 'info',
            self::BoxTransferIn => 'info',
            self::Refund => 'danger',
            self::ClosingAdjustment => 'warning',
            self::CommissionPayout => 'danger',
            self::Reversal => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::OpeningBalance => 'heroicon-m-flag',
            self::SaleReceipt => 'heroicon-m-shopping-bag',
            self::CustomerPayment => 'heroicon-m-arrow-down-tray',
            self::SupplierPayment => 'heroicon-m-arrow-up-tray',
            self::Expense => 'heroicon-m-receipt-percent',
            self::ExchangeOut => 'heroicon-m-arrows-right-left',
            self::ExchangeIn => 'heroicon-m-arrows-right-left',
            self::BoxTransferOut => 'heroicon-m-arrow-right',
            self::BoxTransferIn => 'heroicon-m-arrow-left',
            self::Refund => 'heroicon-m-arrow-uturn-left',
            self::ClosingAdjustment => 'heroicon-m-scale',
            self::CommissionPayout => 'heroicon-m-user',
            self::Reversal => 'heroicon-m-arrow-uturn-left',
        };
    }
}
