<?php

namespace App\Support\Locale;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Display helpers shared by Blade components, Filament columns and PDFs.
 * Dates are always YYYY-MM-DD; numbers follow the user's digit system;
 * codes always stay Western and LTR.
 */
final class Display
{
    public static function number(int|float|string|null $value, int $decimals = 0): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $formatted = number_format((float) $value, $decimals, '.', ',');

        return DigitConverter::forDisplay($formatted, app(LocaleRegistry::class)->digits());
    }

    /**
     * Digits in an already-formatted string (e.g. "3 / 10").
     */
    public static function digits(string|int|null $value): string
    {
        return DigitConverter::forDisplay($value, app(LocaleRegistry::class)->digits());
    }

    public static function date(CarbonInterface|string|null $value): string
    {
        if (blank($value)) {
            return '';
        }

        $date = $value instanceof CarbonInterface ? $value : Carbon::parse($value);

        return self::keepLeftToRight(self::digits($date->format(config('orthotrack.date_format'))));
    }

    public static function dateTime(CarbonInterface|string|null $value): string
    {
        if (blank($value)) {
            return '';
        }

        $date = ($value instanceof CarbonInterface ? $value : Carbon::parse($value))
            ->timezone(config('orthotrack.timezone'));

        return self::keepLeftToRight(self::digits($date->format(config('orthotrack.datetime_format'))));
    }

    /**
     * Arabic-Indic digits are "Arabic numbers" in the Unicode bidi algorithm: a "-" or space
     * between two of them is laid out right-to-left, so ٢٠٢٦-١٠-٢٥ would show as ٢٥-١٠-٢٠٢٦
     * (day first — exactly the expiry-date confusion YYYY-MM-DD is meant to avoid).
     * A LEFT-TO-RIGHT MARK around each separator keeps year-month-day order in every context.
     */
    private static function keepLeftToRight(string $value): string
    {
        if (! preg_match('/[\x{0660}-\x{0669}]/u', $value)) {
            return $value;
        }

        return strtr($value, ['-' => "\u{200E}-\u{200E}", ' ' => "\u{200E} \u{200E}"]);
    }

    /**
     * Codes are never converted. Kept as a named helper so call sites are explicit.
     */
    public static function code(?string $value): string
    {
        return (string) $value;
    }
}
