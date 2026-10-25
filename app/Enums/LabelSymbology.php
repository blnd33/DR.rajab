<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasLabel;

/**
 * Barcode printed on our own labels.
 */
enum LabelSymbology: string implements HasLabel
{
    use EnumHelpers;

    case Code128 = 'code128';
    case DataMatrix = 'datamatrix';
    case Gs1DataMatrix = 'gs1_datamatrix';
    case Qr = 'qr';

    public function getLabel(): string
    {
        return match ($this) {
            self::Code128 => __('Code 128 (1D)'),
            self::DataMatrix => __('DataMatrix (2D)'),
            self::Gs1DataMatrix => __('GS1 DataMatrix (2D)'),
            self::Qr => __('QR code (2D)'),
        };
    }

    /**
     * tc-lib-barcode type name.
     */
    public function libraryType(): string
    {
        return match ($this) {
            self::Code128 => 'C128',
            self::DataMatrix => 'DATAMATRIX',
            self::Gs1DataMatrix => 'DATAMATRIX,S,GS1',
            self::Qr => 'QRCODE,M',
        };
    }

    public function isTwoDimensional(): bool
    {
        return $this !== self::Code128;
    }
}
