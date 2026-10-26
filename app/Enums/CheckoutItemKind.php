<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * One line of a kit checkout snapshot.
 */
enum CheckoutItemKind: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Instrument = 'instrument';
    case Unit = 'unit';
    case Batch = 'batch';

    public function getLabel(): string
    {
        return match ($this) {
            self::Instrument => __('Instrument'),
            self::Unit => __('Serial unit'),
            self::Batch => __('Lot'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Instrument => 'gray',
            self::Unit => 'primary',
            self::Batch => 'primary',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Instrument => 'heroicon-m-wrench',
            self::Unit => 'heroicon-m-qr-code',
            self::Batch => 'heroicon-m-squares-2x2',
        };
    }
}
