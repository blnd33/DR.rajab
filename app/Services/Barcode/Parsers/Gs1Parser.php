<?php

namespace App\Services\Barcode\Parsers;

use App\Services\Barcode\Contracts\ScanParser;
use App\Services\Barcode\ParsedScan;
use App\Support\Gs1\ApplicationIdentifiers as AI;
use App\Support\Gs1\Gtin;
use Illuminate\Support\Carbon;

/**
 * GS1 DataMatrix / GS1-128 element strings, in the forms scanners and people produce:
 *
 *   ]d2010871234567890617260131 10ABC123⟨GS⟩21SN0001   (symbology prefix + GS separators)
 *   010871234567890617260131 10ABC123⟨GS⟩21SN0001       (prefix stripped by the scanner)
 *   (01)08712345678906(17)260131(10)ABC123(21)SN0001     (human-readable, typed or OCR)
 *
 * Reads AIs 01 GTIN, 10 lot, 11 production date, 17 expiry, 21 serial; any other AI is kept
 * in extras. Dates are YYMMDD with the GS1 century rule; day 00 = last day of the month.
 */
class Gs1Parser implements ScanParser
{
    private const GS = "\x1D";

    /** Symbology identifiers (ISO/IEC 15424) that mean "GS1 data follows". */
    private const GS1_PREFIXES = [']d2', ']C1', ']Q3', ']e0', ']J1'];

    /** Placeholders some scanners / keyboard layouts send instead of GS. */
    private const GS_PLACEHOLDERS = ['<GS>', '{GS}', '[GS]', '<FNC1>', '[FNC1]', '␝', '\\x1D', '\\u001d'];

    public function parse(string $raw): ?ParsedScan
    {
        [$data, $hadGs1Prefix] = $this->clean($raw);

        if ($data === '') {
            return null;
        }

        [$elements, $warnings] = str_starts_with($data, '(')
            ? [$this->parseBracketed($data), []]
            : $this->parseElementString($data, $hadGs1Prefix);

        if ($elements === null || $elements === []) {
            return null;
        }

        return $this->toParsedScan($raw, $elements, $warnings);
    }

    /**
     * Strip symbology identifiers, unify GS placeholders and drop a leading FNC1.
     *
     * @return array{0: string, 1: bool} [data, had a GS1 symbology prefix]
     */
    private function clean(string $raw): array
    {
        $data = str_replace(self::GS_PLACEHOLDERS, self::GS, trim($raw, " \t\r\n"));
        $hadGs1Prefix = false;

        foreach (self::GS1_PREFIXES as $prefix) {
            if (str_starts_with($data, $prefix)) {
                $data = substr($data, strlen($prefix));
                $hadGs1Prefix = true;
                break;
            }
        }

        return [ltrim($data, self::GS), $hadGs1Prefix];
    }

    /**
     * "(01)…(17)…(10)…"
     *
     * @return array<string, string>|null
     */
    private function parseBracketed(string $data): ?array
    {
        if (! preg_match_all('/\((\d{2,4})\)([^()]*)/', $data, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $elements = [];

        foreach ($matches as [, $ai, $value]) {
            if (! AI::isKnown($ai)) {
                return null;
            }

            $elements[$ai] = trim(str_replace(self::GS, '', $value));
        }

        return $elements;
    }

    /**
     * Raw element string. Only accepted as GS1 when it had a GS1 symbology prefix, or
     * starts with (01) + 14 digits, or contains a GS — so a plain SKU like "10-200"
     * is never misread as "lot 200".
     *
     * @return array{0: array<string, string>|null, 1: list<string>} [elements, warnings]
     */
    private function parseElementString(string $data, bool $hadGs1Prefix): array
    {
        $warnings = [];

        $looksGs1 = $hadGs1Prefix
            || str_contains($data, self::GS)
            || (bool) preg_match('/^(01|02)\d{14}/', $data);

        if (! $looksGs1) {
            return [null, []];
        }

        $elements = [];
        $position = 0;
        $length = strlen($data);

        while ($position < $length) {
            if ($data[$position] === self::GS) {
                $position++;

                continue;
            }

            $aiLength = AI::aiLength(substr($data, $position, 2));

            if ($aiLength === null) {
                return [null, []];
            }

            $ai = substr($data, $position, $aiLength);
            $position += $aiLength;

            $fixed = AI::fixedDataLength($ai);

            if ($fixed !== null) {
                $value = substr($data, $position, $fixed);
                $position += $fixed;

                if (strlen($value) !== $fixed) {
                    return [null, []];
                }
            } else {
                $end = strpos($data, self::GS, $position);
                $value = substr($data, $position, $end === false ? null : $end - $position);
                $position = $end === false ? $length : $end + 1;

                if (strlen($value) > AI::maxVariableLength($ai)) {
                    // Most likely a scanner that dropped the GS separators: two variable
                    // fields ran together. Keep it, but tell the user to check it.
                    $warnings[] = __('The scanner did not send field separators — check the lot and serial.');
                }
            }

            $elements[$ai] = $value;
        }

        return [$elements, array_values(array_unique($warnings))];
    }

    /**
     * @param  array<string, string>  $elements
     * @param  list<string>  $warnings
     */
    private function toParsedScan(string $raw, array $elements, array $warnings): ?ParsedScan
    {
        $gtin = $elements[AI::GTIN] ?? $elements['02'] ?? null;

        if ($gtin !== null && ! Gtin::isValid($gtin)) {
            $warnings[] = __('The GTIN check digit is wrong — the label may be misread.');
        }

        $expiry = isset($elements[AI::EXPIRY_DATE]) ? $this->date($elements[AI::EXPIRY_DATE]) : null;
        $production = isset($elements[AI::PRODUCTION_DATE]) ? $this->date($elements[AI::PRODUCTION_DATE]) : null;

        if (isset($elements[AI::EXPIRY_DATE]) && $expiry === null) {
            $warnings[] = __('The expiry date on the label is not a real date.');
        }

        $extras = array_diff_key($elements, array_flip([AI::GTIN, AI::LOT, AI::PRODUCTION_DATE, AI::EXPIRY_DATE, AI::SERIAL]));

        $lot = $elements[AI::LOT] ?? null;
        $serial = $elements[AI::SERIAL] ?? null;

        if ($gtin === null && $lot === null && $serial === null && $expiry === null) {
            return null;
        }

        return new ParsedScan(
            parser: 'gs1',
            raw: $raw,
            gtin: $gtin,
            serial: filled($serial) ? $serial : null,
            lot: filled($lot) ? $lot : null,
            expiryDate: $expiry,
            productionDate: $production,
            extras: $extras,
            warnings: $warnings,
        );
    }

    /**
     * GS1 YYMMDD → Y-m-d. Century: within −49…+50 years of today (GS1 GenSpecs 7.12).
     * DD = 00 means the last day of the month.
     */
    private function date(string $yymmdd): ?string
    {
        if (! preg_match('/^(\d{2})(\d{2})(\d{2})$/', $yymmdd, $m)) {
            return null;
        }

        [, $yy, $mm, $dd] = $m;
        $month = (int) $mm;

        if ($month < 1 || $month > 12) {
            return null;
        }

        $currentYear = (int) Carbon::now(config('orthotrack.timezone'))->format('Y');
        $century = intdiv($currentYear, 100) * 100;
        $year = $century + (int) $yy;
        $difference = (int) $yy - ($currentYear % 100);

        if ($difference >= 51) {
            $year -= 100;
        } elseif ($difference <= -50) {
            $year += 100;
        }

        $daysInMonth = Carbon::create($year, $month, 1)->daysInMonth;
        $day = (int) $dd === 0 ? $daysInMonth : (int) $dd;

        if ($day > $daysInMonth) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
