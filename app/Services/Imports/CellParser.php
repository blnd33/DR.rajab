<?php

namespace App\Services\Imports;

use App\Support\Locale\DigitConverter;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Spreadsheet cell → clean value. Excel files always use Western digits, but people type
 * Arabic-Indic digits in Arabic Excel, so every cell is normalised first.
 */
final class CellParser
{
    public static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            // "12345" typed in a number-formatted cell comes back as 12345.0
            $value = (string) (int) $value;
        }

        $value = trim((string) DigitConverter::toWestern((string) $value));

        // Strip non-breaking / zero-width spaces that come with copy-paste.
        $value = trim((string) preg_replace('/[\x{00A0}\x{200B}-\x{200F}\x{FEFF}]/u', ' ', $value));

        return $value === '' ? null : $value;
    }

    /**
     * Excel serial date (e.g. 46418) or text "YYYY-MM-DD" (also "YYYY/MM/DD") → "Y-m-d".
     *
     * @return array{0: ?string, 1: bool} [date, valid]
     */
    public static function date(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [null, true];
        }

        if (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^\d{4,5}(\.\d+)?$/', trim($value)))) {
            $number = (float) $value;

            // Plausible Excel serials: 1990-01-01 … 2150-12-31.
            if ($number >= 32874 && $number <= 91675) {
                try {
                    return [ExcelDate::excelToDateTimeObject($number)->format('Y-m-d'), true];
                } catch (Throwable) {
                    return [null, false];
                }
            }

            return [null, false];
        }

        $text = (string) self::text($value);

        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})(?:[ T].*)?$/', $text, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return [sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]), true];
        }

        return [null, false];
    }

    /**
     * Money / decimal ≥ 0 as a 4-decimal string.
     *
     * @return array{0: ?string, 1: bool}
     */
    public static function decimal(mixed $value): array
    {
        $text = self::text($value);

        if ($text === null) {
            return [null, true];
        }

        $text = str_replace([',', ' '], '', $text);

        try {
            $number = BigDecimal::of($text);
        } catch (MathException) {
            return [null, false];
        }

        if ($number->isNegative()) {
            return [null, false];
        }

        return [(string) $number->toScale(4, RoundingMode::HalfUp), true];
    }

    /**
     * Whole number ≥ 0.
     *
     * @return array{0: ?int, 1: bool}
     */
    public static function integer(mixed $value): array
    {
        $text = self::text($value);

        if ($text === null) {
            return [null, true];
        }

        $text = str_replace([',', ' '], '', $text);

        if (! preg_match('/^\d+(\.0+)?$/', $text)) {
            return [null, false];
        }

        return [(int) $text, true];
    }

    /**
     * Same comparison everywhere: case-insensitive, trimmed.
     */
    public static function key(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }
}
