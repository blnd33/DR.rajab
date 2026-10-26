<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * What waits in the approvals queue.
 */
enum ApprovalType: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case SupplierPayment = 'supplier_payment';
    case Expense = 'expense';
    case CommissionPayout = 'commission_payout';

    public function getLabel(): string
    {
        return match ($this) {
            self::SupplierPayment => __('Supplier payment'),
            self::Expense => __('Expense'),
            self::CommissionPayout => __('Commission payout'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::SupplierPayment => 'warning',
            self::Expense => 'warning',
            self::CommissionPayout => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::SupplierPayment => 'heroicon-m-banknotes',
            self::Expense => 'heroicon-m-receipt-percent',
            self::CommissionPayout => 'heroicon-m-user',
        };
    }
}
