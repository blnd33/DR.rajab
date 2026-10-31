<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * How purchased items were received.
 */
enum ReceiptMethod: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Scan = 'scan';
    case Manual = 'manual';
    case Excel = 'excel';

    public function getLabel(): string
    {
        return match ($this) {
            self::Scan => __('Scanned'),
            self::Manual => __('Typed'),
            self::Excel => __('Excel'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Scan => 'info',
            self::Manual => 'gray',
            self::Excel => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Scan => 'ot-barcode',
            self::Manual => 'heroicon-m-pencil',
            self::Excel => 'heroicon-m-table-cells',
        };
    }
}
