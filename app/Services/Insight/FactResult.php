<?php

namespace App\Services\Insight;

/**
 * The answer of one fact, as plain data: a headline (the main number), up to a few lines,
 * the raw numbers (for the short push text and for saving) and a link to the full screen.
 * Built in the reader's language; saved summaries keep the text as it was that morning.
 */
final class FactResult
{
    public const NEUTRAL = 'neutral';

    public const GOOD = 'good';

    public const WARNING = 'warning';

    public const DANGER = 'danger';

    /**
     * @param  list<array{label: string, value: string, url?: ?string, code?: bool}>  $lines
     * @param  array<string, int|float|string>  $numbers
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly string $headline,
        public readonly array $lines = [],
        public readonly array $numbers = [],
        public readonly ?string $url = null,
        public readonly string $tone = self::NEUTRAL,
        public readonly ?string $note = null,
    ) {}

    /**
     * Nothing happened / nothing waiting: every number is zero and there are no lines.
     */
    public function isEmpty(): bool
    {
        foreach ($this->numbers as $value) {
            if ((float) $value != 0.0) {
                return false;
            }
        }

        return $this->lines === [];
    }

    public function number(string $name): int|float|string
    {
        return $this->numbers[$name] ?? 0;
    }

    /**
     * @return array{key: string, title: string, headline: string, lines: list<array<string, mixed>>, numbers: array<string, int|float|string>, url: ?string, tone: string, note: ?string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'headline' => $this->headline,
            'lines' => $this->lines,
            'numbers' => $this->numbers,
            'url' => $this->url,
            'tone' => $this->tone,
            'note' => $this->note,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['key'] ?? ''),
            (string) ($data['title'] ?? ''),
            (string) ($data['headline'] ?? ''),
            array_values($data['lines'] ?? []),
            $data['numbers'] ?? [],
            $data['url'] ?? null,
            (string) ($data['tone'] ?? self::NEUTRAL),
            $data['note'] ?? null,
        );
    }
}
