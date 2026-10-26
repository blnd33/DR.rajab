<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Supplier payment.
 */
enum SupplierPaymentStatus: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case PendingApproval = 'pending_approval';
    case Posted = 'posted';
    case Rejected = 'rejected';
    case Voided = 'voided';

    public function getLabel(): string
    {
        return match ($this) {
            self::PendingApproval => __('Waiting for approval'),
            self::Posted => __('Paid'),
            self::Rejected => __('Rejected'),
            self::Voided => __('Voided'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::PendingApproval => 'warning',
            self::Posted => 'success',
            self::Rejected => 'danger',
            self::Voided => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::PendingApproval => 'heroicon-m-clock',
            self::Posted => 'heroicon-m-check-circle',
            self::Rejected => 'heroicon-m-x-circle',
            self::Voided => 'heroicon-m-x-mark',
        };
    }
}
