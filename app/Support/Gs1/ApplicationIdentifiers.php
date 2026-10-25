<?php

namespace App\Support\Gs1;

/**
 * GS1 application identifiers (AIs) we read, from the GS1 General Specifications.
 *
 * The AI length follows from its first two digits; "predefined length" AIs need no
 * GS (FNC1) separator after them, every other AI is variable-length and ends at a GS
 * or at the end of the data.
 */
final class ApplicationIdentifiers
{
    /** First two digits → AI length (2, 3 or 4 digits). */
    private const AI_LENGTH_BY_PREFIX = [
        '00' => 2, '01' => 2, '02' => 2, '03' => 2, '04' => 2,
        '10' => 2, '11' => 2, '12' => 2, '13' => 2, '14' => 2, '15' => 2, '16' => 2, '17' => 2, '18' => 2, '19' => 2,
        '20' => 2, '21' => 2, '22' => 2, '30' => 2, '37' => 2,
        '90' => 2, '91' => 2, '92' => 2, '93' => 2, '94' => 2, '95' => 2, '96' => 2, '97' => 2, '98' => 2, '99' => 2,
        '23' => 3, '24' => 3, '25' => 3, '40' => 3, '41' => 3, '42' => 3, '43' => 3,
        '31' => 4, '32' => 4, '33' => 4, '34' => 4, '35' => 4, '36' => 4, '39' => 4,
        '70' => 4, '71' => 4, '72' => 4, '80' => 4, '81' => 4, '82' => 4,
    ];

    /** First two digits → fixed data length (GS1 "predefined length" table). */
    private const FIXED_DATA_LENGTH_BY_PREFIX = [
        '00' => 18, '01' => 14, '02' => 14, '03' => 14, '04' => 16,
        '11' => 6, '12' => 6, '13' => 6, '14' => 6, '15' => 6, '16' => 6, '17' => 6, '18' => 6, '19' => 6,
        '20' => 2,
        '31' => 6, '32' => 6, '33' => 6, '34' => 6, '35' => 6, '36' => 6,
        '41' => 13,
    ];

    /** Maximum length of variable AIs we know; others default to 90. */
    private const MAX_VARIABLE_LENGTH = [
        '10' => 20, '21' => 20, '22' => 20, '240' => 30, '241' => 30, '250' => 30, '30' => 8, '37' => 8,
        '400' => 30, '420' => 20, '7240' => 20,
    ];

    /** AIs we map to fields; anything else is kept in "extras". */
    public const GTIN = '01';

    public const LOT = '10';

    public const PRODUCTION_DATE = '11';

    public const EXPIRY_DATE = '17';

    public const SERIAL = '21';

    public static function aiLength(string $data): ?int
    {
        return self::AI_LENGTH_BY_PREFIX[substr($data, 0, 2)] ?? null;
    }

    public static function fixedDataLength(string $ai): ?int
    {
        return self::FIXED_DATA_LENGTH_BY_PREFIX[substr($ai, 0, 2)] ?? null;
    }

    public static function maxVariableLength(string $ai): int
    {
        return self::MAX_VARIABLE_LENGTH[$ai] ?? 90;
    }

    public static function isDate(string $ai): bool
    {
        return in_array($ai, ['11', '12', '13', '15', '16', '17'], true);
    }

    public static function isKnown(string $ai): bool
    {
        return self::aiLength($ai) === strlen($ai);
    }
}
