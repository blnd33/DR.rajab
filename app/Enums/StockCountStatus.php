<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Stock count progress.
 */
enum StockCountStatus: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case InProgress = 'in_progress';
    case Submitted = 'submitted';
    case Reviewed = 'reviewed';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::InProgress => __('Counting'),
            self::Submitted => __('Waiting for review'),
            self::Reviewed => __('Reviewed'),
            self::Closed => __('Closed'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::InProgress => 'info',
            self::Submitted => 'warning',
            self::Reviewed => 'primary',
            self::Closed => 'success',
            self::Cancelled => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::InProgress => 'ot-barcode',
            self::Submitted => 'heroicon-m-clock',
            self::Reviewed => 'heroicon-m-clipboard-document-check',
            self::Closed => 'heroicon-m-check-circle',
            self::Cancelled => 'heroicon-m-x-circle',
        };
    }
}
