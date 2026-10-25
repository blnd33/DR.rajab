<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasLabel;

/**
 * Kind of extra code stored in product_barcodes.
 */
enum BarcodeSymbology: string implements HasLabel
{
    use EnumHelpers;

    case Gtin = 'gtin';
    case Ean13 = 'ean13';
    case Code128 = 'code128';
    case Internal = 'internal';
    case Supplier = 'supplier';

    public function getLabel(): string
    {
        return match ($this) {
            self::Gtin => __('GTIN (GS1)'),
            self::Ean13 => __('EAN-13'),
            self::Code128 => __('Code 128'),
            self::Internal => __('Our own label'),
            self::Supplier => __('Supplier code'),
        };
    }
}
