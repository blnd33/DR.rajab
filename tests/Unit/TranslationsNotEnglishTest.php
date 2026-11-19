<?php

/*
 * P5-9: Arabic and Sorani strings are never left in English. Codes and technical names that
 * stay in Latin letters on purpose are listed here.
 */

const LATIN_ON_PURPOSE = ['Code 128', 'EAN-13', 'Excel', 'GTIN', 'GTIN (GS1)', 'IBAN', 'KurdishMedicus', 'SKU'];

it('has no Arabic or Sorani string left in English', function (string $locale) {
    $english = json_decode((string) file_get_contents(lang_path('en.json')), true);
    $translated = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true);
    $leftovers = [];

    foreach ($english as $key => $value) {
        $text = (string) ($translated[$key] ?? '');
        $letters = preg_replace('/:[a-z_]+|\{[^}]*\}|\[[^\]]*\]/', '', $text);

        if ($text === $key && preg_match('/[A-Za-z]{3,}/', (string) $letters) === 1 && ! in_array($key, LATIN_ON_PURPOSE, true)) {
            $leftovers[] = $key;
        }
    }

    expect($leftovers)->toBe([]);
})->with(['ar', 'ckb']);
