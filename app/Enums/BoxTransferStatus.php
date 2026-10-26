<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Cash moved between boxes.
 */
enum BoxTransferStatus: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Sent = 'sent';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Sent => __('On its way'),
            self::Received => __('Received'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Sent => 'info',
            self::Received => 'success',
            self::Cancelled => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Sent => 'heroicon-m-truck',
            self::Received => 'heroicon-m-check-circle',
            self::Cancelled => 'heroicon-m-x-circle',
        };
    }
}
