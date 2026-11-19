<?php

namespace App\Support;

use App\Enums\PlateKind;
use App\Enums\PlateRegion;

/**
 * Iraqi number plates.
 *
 *  - English plate: a 2-digit code, one Latin letter and a number of 1–6 digits → "24 A 3003".
 *  - Arabic plate:  a number of 1–6 digits and "Iraq" or the governorate → "12345 دهوك".
 *
 * Digits typed in Arabic-Indic are stored in Western digits (like every code in the system).
 */
final class Plate
{
    public const CODE_PATTERN = '/^\d{2}$/';

    public const LETTER_PATTERN = '/^[A-Z]$/';

    public const NUMBER_PATTERN = '/^\d{1,6}$/';

    /**
     * Clean the typed parts: Western digits, no spaces, upper-case letter; the parts the kind
     * does not use are emptied.
     *
     * @param  array{plate_kind?: mixed, plate_code?: mixed, plate_letter?: mixed, plate_number?: mixed, plate_region?: mixed}  $data
     * @return array{plate_kind: ?string, plate_code: ?string, plate_letter: ?string, plate_number: ?string, plate_region: ?string}
     */
    public static function normalize(array $data): array
    {
        $kind = $data['plate_kind'] ?? null;
        $kind = $kind instanceof PlateKind ? $kind : PlateKind::tryFrom((string) $kind);
        $region = $data['plate_region'] ?? null;
        $region = $region instanceof PlateRegion ? $region : PlateRegion::tryFrom((string) $region);

        $clean = fn (mixed $value) => ($v = preg_replace('/\s+/u', '', self::westernDigits((string) $value))) === '' ? null : $v;

        return [
            'plate_kind' => $kind?->value,
            'plate_code' => $kind === PlateKind::English ? $clean($data['plate_code'] ?? '') : null,
            'plate_letter' => $kind === PlateKind::English && $clean($data['plate_letter'] ?? '') !== null ? mb_strtoupper($clean($data['plate_letter'])) : null,
            'plate_number' => $clean($data['plate_number'] ?? ''),
            'plate_region' => $kind === PlateKind::Arabic ? $region?->value : null,
        ];
    }

    /**
     * field => message for every part that is wrong (empty when the plate is valid).
     *
     * @param  array{plate_kind: ?string, plate_code: ?string, plate_letter: ?string, plate_number: ?string, plate_region: ?string}  $plate  normalized
     * @return array<string, string>
     */
    public static function errors(array $plate): array
    {
        $kind = PlateKind::tryFrom((string) $plate['plate_kind']);
        $errors = [];

        if ($kind === null) {
            return ['plate_kind' => __('Choose the plate type.')];
        }

        if ($kind === PlateKind::English) {
            if (! preg_match(self::CODE_PATTERN, (string) $plate['plate_code'])) {
                $errors['plate_code'] = __('The code is 2 digits, e.g. 24.');
            }

            if (! preg_match(self::LETTER_PATTERN, (string) $plate['plate_letter'])) {
                $errors['plate_letter'] = __('One English letter, e.g. A.');
            }
        }

        if ($kind === PlateKind::Arabic && PlateRegion::tryFrom((string) $plate['plate_region']) === null) {
            $errors['plate_region'] = __('Choose Iraq or the city written on the plate.');
        }

        if (! preg_match(self::NUMBER_PATTERN, (string) $plate['plate_number'])) {
            $errors['plate_number'] = __('The number is 1 to 6 digits, e.g. 3003.');
        }

        return $errors;
    }

    /**
     * The plate as written on the car: "24 A 3003" or "12345 دهوك".
     */
    public static function format(PlateKind|string|null $kind, ?string $code, ?string $letter, ?string $number, PlateRegion|string|null $region): string
    {
        $kind = $kind instanceof PlateKind ? $kind : PlateKind::tryFrom((string) $kind);
        $region = $region instanceof PlateRegion ? $region : PlateRegion::tryFrom((string) $region);

        return match ($kind) {
            PlateKind::English => trim("{$code} {$letter} {$number}"),
            PlateKind::Arabic => trim($number.' '.($region?->onPlate() ?? '')),
            null => (string) $number,
        };
    }

    private static function westernDigits(string $value): string
    {
        return strtr($value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }
}
