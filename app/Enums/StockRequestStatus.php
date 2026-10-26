<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Life of a stock request (Module 9).
 */
enum StockRequestStatus: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Pending = 'pending';
    case Approved = 'approved';
    case PartiallyApproved = 'partially_approved';
    case Rejected = 'rejected';
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => __('Waiting for approval'),
            self::Approved => __('Approved'),
            self::PartiallyApproved => __('Partly approved'),
            self::Rejected => __('Rejected'),
            self::Fulfilled => __('Delivered'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::PartiallyApproved => 'success',
            self::Rejected => 'danger',
            self::Fulfilled => 'primary',
            self::Cancelled => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Pending => 'heroicon-m-clock',
            self::Approved => 'heroicon-m-check-circle',
            self::PartiallyApproved => 'heroicon-m-check',
            self::Rejected => 'heroicon-m-x-circle',
            self::Fulfilled => 'heroicon-m-archive-box',
            self::Cancelled => 'heroicon-m-x-mark',
        };
    }
}
