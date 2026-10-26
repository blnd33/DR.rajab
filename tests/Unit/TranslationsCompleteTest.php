<?php

use App\Services\Permissions\PermissionRegistry;

/*
 * Every UI string used in app/ and resources/views must exist in every language file,
 * so nobody ships a screen that silently falls back to English.
 */

function usedTranslationKeys(): array
{
    $keys = [];
    $pattern = '/(?:\b__|\btrans_choice|Msg::key)\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/';

    foreach (['app', 'resources/views'] as $dir) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir)));

        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            preg_match_all($pattern, file_get_contents($file->getPathname()), $matches);

            foreach ($matches[1] as $key) {
                $key = str_replace("\\'", "'", $key);

                if (! str_contains($key, '::')) {
                    $keys[$key] = true;
                }
            }
        }
    }

    foreach (PermissionRegistry::all() as $definition) {
        $keys[$definition['label']] = true;
    }

    return array_keys($keys);
}

it('has every UI string translated', function (string $locale) {
    $translations = json_decode(file_get_contents(lang_path("{$locale}.json")), true);
    $missing = array_values(array_filter(usedTranslationKeys(), fn ($key) => ! array_key_exists($key, $translations)));

    expect($missing)->toBeEmpty("Missing in lang/{$locale}.json:\n".implode("\n", $missing));
})->with(['en', 'ar', 'ckb']);

it('covers every configured language', function () {
    expect(array_keys(config('locales.supported')))->toEqualCanonicalizing(['en', 'ar', 'ckb']);
});

it('keeps :placeholders intact in every translation', function (string $locale) {
    $translations = json_decode(file_get_contents(lang_path("{$locale}.json")), true);

    foreach ($translations as $key => $value) {
        preg_match_all('/:[a-z_]+/', $key, $expected);

        foreach (array_unique($expected[0]) as $placeholder) {
            expect(str_contains($value, $placeholder))->toBeTrue("[{$locale}] \"{$key}\" lost {$placeholder}");
        }
    }
})->with(['ar', 'ckb']);
