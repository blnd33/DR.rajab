<?php

namespace App\Services\Barcode\Parsers;

use App\Services\Barcode\Contracts\ScanParser;
use App\Services\Barcode\ParsedScan;

/**
 * Our own 2D labels (LabelRenderer) for items without a usable GS1 code:
 *
 *   OT1|U|<serial>               a serial-tracked unit
 *   OT1|P|<sku>                  a product
 *   OT1|L|<sku>|<lot>|<expiry>   a lot (expiry YYYY-MM-DD, may be empty)
 *
 * Kits and instruments (Phase 3) add K and I.
 */
class InternalLabelParser implements ScanParser
{
    public const PREFIX = 'OT1|';

    public function parse(string $raw): ?ParsedScan
    {
        $raw = trim($raw);

        if (! str_starts_with($raw, self::PREFIX)) {
            return null;
        }

        $parts = explode('|', $raw);
        $kind = $parts[1] ?? '';

        return match ($kind) {
            'U' => filled($parts[2] ?? null) ? new ParsedScan(parser: 'internal', raw: $raw, serial: $parts[2]) : null,
            'P' => filled($parts[2] ?? null) ? new ParsedScan(parser: 'internal', raw: $raw, sku: $parts[2]) : null,
            'L' => filled($parts[2] ?? null) ? new ParsedScan(
                parser: 'internal',
                raw: $raw,
                sku: $parts[2],
                lot: filled($parts[3] ?? null) ? $parts[3] : null,
                expiryDate: preg_match('/^\d{4}-\d{2}-\d{2}$/', $parts[4] ?? '') ? $parts[4] : null,
            ) : null,
            default => null,
        };
    }

    public static function unit(string $serial): string
    {
        return self::PREFIX.'U|'.$serial;
    }

    public static function product(string $sku): string
    {
        return self::PREFIX.'P|'.$sku;
    }

    public static function lot(string $sku, ?string $lot, ?string $expiry): string
    {
        return self::PREFIX.'L|'.$sku.'|'.($lot ?? '').'|'.($expiry ?? '');
    }
}
