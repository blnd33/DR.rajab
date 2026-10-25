<?php

namespace App\Support\Locale;

use App\Enums\DigitSystem;

/**
 * ICU locale string with the configured numbering system, e.g. "ar-u-nu-arab"
 * or "ckb-u-nu-latn", so intl/Number formatting follows Settings.
 */
final class NumberLocale
{
    public static function current(): string
    {
        $registry = app(LocaleRegistry::class);
        $locale = $registry->resolve(app()->getLocale());
        $numbering = $registry->digits($locale) === DigitSystem::ArabicIndic ? 'arab' : 'latn';

        return "{$locale}-u-nu-{$numbering}";
    }
}
