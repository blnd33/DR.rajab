<?php

namespace App\Services\Barcode\Parsers;

use App\Services\Barcode\Contracts\ScanParser;
use App\Services\Barcode\ParsedScan;
use App\Support\Gs1\Gtin;

/**
 * Fallback: any other code (Code 128, EAN-13, a typed SKU or serial). The resolver tries
 * it as a serial, an SKU, a GTIN, an extra product barcode and a lot, in that order.
 * Always last in the parser chain.
 */
class PlainCodeParser implements ScanParser
{
    public function parse(string $raw): ?ParsedScan
    {
        // Control characters (GS, CR…) never belong to a plain code.
        $code = trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $raw));

        if ($code === '' || mb_strlen($code) > 128) {
            return null;
        }

        return new ParsedScan(
            parser: 'plain',
            raw: $raw,
            gtin: Gtin::isValid($code) ? $code : null,
            code: $code,
        );
    }
}
