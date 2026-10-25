<?php

namespace App\Support\Gs1;

/**
 * GTIN-8/12/13/14 helpers. Products store the GTIN as printed; matching compares the
 * 14-digit form so an EAN-13 on the catalog matches a GS1 (01) GTIN-14 on the box.
 */
final class Gtin
{
    public static function isValid(string $code): bool
    {
        if (! preg_match('/^\d{8}$|^\d{12,14}$/', $code)) {
            return false;
        }

        return self::checkDigit(substr($code, 0, -1)) === (int) substr($code, -1);
    }

    /**
     * GS1 mod-10 check digit for the digits before it.
     */
    public static function checkDigit(string $digits): int
    {
        $sum = 0;
        $weight = 3;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $sum += (int) $digits[$i] * $weight;
            $weight = $weight === 3 ? 1 : 3;
        }

        return (10 - ($sum % 10)) % 10;
    }

    /**
     * 14-digit form (left-padded with zeros), or null when not a GTIN.
     */
    public static function toGtin14(string $code): ?string
    {
        $code = trim($code);

        return self::isValid($code) ? str_pad($code, 14, '0', STR_PAD_LEFT) : null;
    }

    /**
     * Every printed form that means the same GTIN (14, 13, 12 digits).
     *
     * @return list<string>
     */
    public static function variants(string $gtin14): array
    {
        $variants = [$gtin14];

        foreach ([13, 12, 8] as $length) {
            $short = substr($gtin14, 14 - $length);

            if (ltrim(substr($gtin14, 0, 14 - $length), '0') === '') {
                $variants[] = $short;
            }
        }

        return array_values(array_unique($variants));
    }
}
