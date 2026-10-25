<?php

namespace App\Models\Concerns;

use Spatie\Translatable\HasTranslations;

/**
 * spatie/laravel-translatable, but blank languages are not stored, so the
 * fallback chain (user locale → en → ar) works instead of showing "".
 */
trait HasCleanTranslations
{
    use HasTranslations {
        setTranslations as protected baseSetTranslations;
    }

    public function setTranslations(string $key, array $translations): self
    {
        $translations = array_filter($translations, fn ($value) => $value !== null && trim((string) $value) !== '');

        // Replace the whole set so removed languages are really removed.
        $this->attributes[$key] = $this->asJson([]);

        return $this->baseSetTranslations($key, $translations);
    }
}
