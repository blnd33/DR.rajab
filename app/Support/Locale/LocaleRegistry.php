<?php

namespace App\Support\Locale;

use App\Enums\DigitSystem;
use App\Services\Settings\SettingsRepository;

/**
 * Reads config/locales.php. Adding a language = one config entry + lang files.
 */
class LocaleRegistry
{
    public function __construct(private readonly SettingsRepository $settings) {}

    /**
     * @return array<string, array{name: string, native: string, dir: string, digits: string, font: string, required_for_translations: bool}>
     */
    public function all(): array
    {
        return config('locales.supported');
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_keys($this->all());
    }

    public function isSupported(?string $code): bool
    {
        return $code !== null && array_key_exists($code, $this->all());
    }

    public function default(): string
    {
        return config('locales.default', 'en');
    }

    public function resolve(?string $code): string
    {
        return $this->isSupported($code) ? $code : $this->default();
    }

    public function direction(?string $code = null): string
    {
        return $this->all()[$this->resolve($code ?? app()->getLocale())]['dir'];
    }

    public function isRtl(?string $code = null): bool
    {
        return $this->direction($code) === 'rtl';
    }

    public function nativeName(string $code): string
    {
        return $this->all()[$this->resolve($code)]['native'];
    }

    /**
     * code => native name, for language pickers.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return array_map(fn (array $locale) => $locale['native'], $this->all());
    }

    /**
     * Display digit system for a language; Settings can override the config default.
     */
    public function digits(?string $code = null): DigitSystem
    {
        $code = $this->resolve($code ?? app()->getLocale());
        $value = $this->settings->get("locale.digits.{$code}", $this->all()[$code]['digits']);

        return DigitSystem::tryFrom((string) $value) ?? DigitSystem::Latin;
    }

    public function fontStack(?string $code = null): string
    {
        $key = $this->all()[$this->resolve($code ?? app()->getLocale())]['font'];

        return config("locales.fonts.{$key}");
    }

    /**
     * Fallback chain for translatable DB fields: requested → en → ar (deduplicated).
     *
     * @return list<string>
     */
    public function fallbackChain(?string $code = null): array
    {
        $chain = [$this->resolve($code ?? app()->getLocale()), ...config('locales.fallback_chain', ['en'])];

        return array_values(array_unique($chain));
    }
}
