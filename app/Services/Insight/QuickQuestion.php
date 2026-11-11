<?php

namespace App\Services\Insight;

/**
 * One ready-made question of Quick answers. It is answered by a fact (with options), by the
 * whole day's facts (day = today / yesterday), or it asks for a code (prompt).
 */
final class QuickQuestion
{
    /**
     * @param  array<string, mixed>  $options  passed to the fact (FactContext::option)
     * @param  list<string>  $keywords  words in English, Arabic and Kurdish that point to it
     */
    public function __construct(
        public readonly string $key,
        public readonly string $group,
        public readonly string $label,
        public readonly ?string $fact = null,
        public readonly array $options = [],
        public readonly ?string $day = null,
        public readonly ?string $prompt = null,
        public readonly ?string $permission = null,
        public readonly array $keywords = [],
    ) {}

    public function isPrompt(): bool
    {
        return $this->prompt !== null;
    }

    public function isDay(): bool
    {
        return $this->day !== null;
    }
}
