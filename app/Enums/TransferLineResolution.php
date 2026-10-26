<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * How a transfer difference was resolved.
 */
enum TransferLineResolution: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case None = 'none';
    case Found = 'found';
    case WrittenOff = 'written_off';
    case ReturnedToSender = 'returned_to_sender';

    public function getLabel(): string
    {
        return match ($this) {
            self::None => __('Open'),
            self::Found => __('Found and received'),
            self::WrittenOff => __('Written off as lost'),
            self::ReturnedToSender => __('Returned to the sender'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::None => 'gray',
            self::Found => 'success',
            self::WrittenOff => 'danger',
            self::ReturnedToSender => 'info',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::None => 'heroicon-m-ellipsis-horizontal',
            self::Found => 'heroicon-m-check-circle',
            self::WrittenOff => 'heroicon-m-trash',
            self::ReturnedToSender => 'heroicon-m-arrow-uturn-left',
        };
    }
}
