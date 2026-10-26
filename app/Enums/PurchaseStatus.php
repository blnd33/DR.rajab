<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Supplier invoice.
 */
enum PurchaseStatus: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Confirmed => __('Confirmed'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Confirmed => 'success',
            self::Cancelled => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Draft => 'heroicon-m-pencil-square',
            self::Confirmed => 'heroicon-m-check-circle',
            self::Cancelled => 'heroicon-m-x-circle',
        };
    }
}
