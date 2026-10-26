<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Life of an inter-branch transfer (Module 8).
 */
enum TransferStatus: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Draft = 'draft';
    case InTransit = 'in_transit';
    case Received = 'received';
    case ReceivedWithDiscrepancy = 'received_with_discrepancy';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::InTransit => __('In transit'),
            self::Received => __('Received'),
            self::ReceivedWithDiscrepancy => __('Received with differences'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::InTransit => 'info',
            self::Received => 'success',
            self::ReceivedWithDiscrepancy => 'warning',
            self::Cancelled => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Draft => 'heroicon-m-pencil-square',
            self::InTransit => 'heroicon-m-truck',
            self::Received => 'heroicon-m-check-circle',
            self::ReceivedWithDiscrepancy => 'heroicon-m-exclamation-triangle',
            self::Cancelled => 'heroicon-m-x-circle',
        };
    }
}
