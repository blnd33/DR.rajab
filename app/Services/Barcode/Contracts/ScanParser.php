<?php

namespace App\Services\Barcode\Contracts;

use App\Services\Barcode\ParsedScan;

/**
 * One barcode format. Parsers run in the order of config('orthotrack.scan_parsers');
 * the first one that returns a result wins. A new vendor format = one new class.
 */
interface ScanParser
{
    /**
     * Null when the raw text is not in this parser's format.
     */
    public function parse(string $raw): ?ParsedScan;
}
