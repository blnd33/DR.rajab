<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * How much of a purchase is in stock.
 */
enum ReceivingStatus: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case NotReceived = 'not_received';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';

    public function getLabel(): string
    {
        return match ($this) {
            self::NotReceived => __('Not received'),
            self::PartiallyReceived => __('Partly received'),
            self::Received => __('Received'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::NotReceived => 'gray',
            self::PartiallyReceived => 'warning',
            self::Received => 'success',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::NotReceived => 'heroicon-m-clock',
            self::PartiallyReceived => 'heroicon-m-inbox-arrow-down',
            self::Received => 'heroicon-m-check-circle',
        };
    }
}
