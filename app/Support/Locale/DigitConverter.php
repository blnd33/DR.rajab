<?php

namespace App\Support\Locale;

use App\Enums\DigitSystem;

/**
 * Converts between Western digits (storage, codes) and Arabic-Indic digits (display).
 *
 * Input normalisation accepts Arabic-Indic (U+0660–0669) and Persian/Extended
 * Arabic-Indic (U+06F0–06F9) digits plus the Arabic decimal (٫) and
 * thousands (٬) separators.
 */
final class DigitConverter
{
    private const WESTERN = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    private const ARABIC_INDIC = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    private const PERSIAN = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    /** Input keys whose values must never be altered. */
    private const PROTECTED_KEY_PATTERN = '/(password|secret|token|_token|recovery)/i';

    /**
     * Normalise any digit system to Western. Safe for codes, numbers and free text.
     */
    public static function toWestern(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        // Fast path: plain ASCII has nothing to convert.
        if (! preg_match('/[\x{0660}-\x{0669}\x{06F0}-\x{06F9}\x{066B}\x{066C}]/u', $value)) {
            return $value;
        }

        $value = str_replace(self::ARABIC_INDIC, self::WESTERN, $value);
        $value = str_replace(self::PERSIAN, self::WESTERN, $value);

        // ٫ (U+066B) decimal separator → '.', ٬ (U+066C) thousands separator → removed.
        return str_replace(['٫', '٬'], ['.', ''], $value);
    }

    /**
     * Format a Western-digit string for display in the given digit system.
     * Never use this for codes (serial, lot, SKU, barcode, phone, document numbers).
     */
    public static function forDisplay(string|int|float|null $value, DigitSystem $system): string
    {
        if ($value === null) {
            return '';
        }

        $value = (string) $value;

        if ($system === DigitSystem::Latin) {
            return $value;
        }

        $value = str_replace(self::WESTERN, self::ARABIC_INDIC, $value);

        // Use Arabic separators so "1,234.5" reads "١٬٢٣٤٫٥".
        return strtr($value, [',' => '٬', '.' => '٫']);
    }

    /**
     * Recursively normalise request input, skipping protected keys (passwords, tokens).
     *
     * @param  array<array-key, mixed>  $input
     * @return array<array-key, mixed>
     */
    public static function normalizeArray(array $input): array
    {
        foreach ($input as $key => $value) {
            if (is_string($key) && preg_match(self::PROTECTED_KEY_PATTERN, $key)) {
                continue;
            }

            if (is_array($value)) {
                $input[$key] = self::normalizeArray($value);
            } elseif (is_string($value)) {
                $input[$key] = self::toWestern($value);
            }
        }

        return $input;
    }

    /**
     * Normalise a Livewire update payload in place: only `updates` and call
     * `params` are touched — the checksummed `snapshot` is left alone.
     *
     * @param  array<int, array<string, mixed>>  $components
     * @return array<int, array<string, mixed>>
     */
    public static function normalizeLivewirePayload(array $components): array
    {
        foreach ($components as $i => $component) {
            if (isset($component['updates']) && is_array($component['updates'])) {
                $components[$i]['updates'] = self::normalizeArray($component['updates']);
            }

            foreach ($component['calls'] ?? [] as $j => $call) {
                if (is_array($call['params'] ?? null)) {
                    $components[$i]['calls'][$j]['params'] = self::normalizeArray($call['params']);
                }
            }
        }

        return $components;
    }
}
