<?php

namespace App\Services\Insight;

use App\Models\User;
use App\Support\Locale\DigitConverter;
use App\Support\Locale\Display;
use Illuminate\Support\Carbon;

/**
 * Quick answers (Phase 6, no AI): a ready-made question or a typed / scanned text → an answer
 * message with cards. Shared by the admin page and the phone screen. Nothing typed is stored.
 *
 * An answer message: {id, from: 'bot', text?, period?, cards: list<FactResult array>,
 * suggestions: list<{key, label}>, focus?: bool (ask for a code)}.
 */
final class QuickAnswers
{
    public const PERIODS = ['today', 'yesterday', 'week', 'month', 'custom'];

    public function __construct(
        private readonly QuickQuestions $questions,
        private readonly FactRegistry $facts,
        private readonly CodeLookup $codes,
        private readonly KeywordMatcher $matcher,
    ) {}

    public static function canUse(User $user): bool
    {
        return $user->is_active && $user->can('assistant.use');
    }

    /**
     * @return array<string, string> period → label
     */
    public static function periodOptions(): array
    {
        return [
            'today' => __('Today'),
            'yesterday' => __('Yesterday'),
            'week' => __('This week'),
            'month' => __('This month'),
            'custom' => __('Choose dates'),
        ];
    }

    /**
     * The questions this person may ask, by group.
     *
     * @return array<string, array{label: string, questions: list<array{key: string, label: string, period: bool}>}>
     */
    public function menu(User $user): array
    {
        $menu = [];

        foreach ($this->questions->visibleTo($user) as $question) {
            $menu[$question->group] ??= ['label' => QuickQuestions::groups()[$question->group] ?? $question->group, 'questions' => []];
            $menu[$question->group]['questions'][] = [
                'key' => $question->key,
                'label' => $question->label,
                'period' => $this->questions->usesPeriod($question),
            ];
        }

        return $menu;
    }

    /**
     * @param  array{period?: ?string, from?: ?string, until?: ?string, branch?: int|string|null, currency?: ?string}  $choices
     * @return array<string, mixed>
     */
    public function ask(User $user, string $key, array $choices = []): array
    {
        $question = $this->questions->find($key);

        if ($question === null || ! $this->questions->canAsk($user, $question)) {
            return $this->message(text: __('This question is not available to you.'));
        }

        if ($question->isPrompt()) {
            return $this->message(text: $question->prompt, focus: true);
        }

        $branch = filled($choices['branch'] ?? null) ? (int) $choices['branch'] : null;
        $currency = (string) ($choices['currency'] ?? 'USD');

        if ($question->isDay()) {
            $day = $question->day === 'yesterday' ? now(config('orthotrack.timezone'))->subDay() : now(config('orthotrack.timezone'));
            $context = FactContext::day($user, $day, $branch, $currency);
            $cards = [];

            foreach ($this->facts->buildAll($context) as $results) {
                foreach ($results as $result) {
                    if (! $result->isEmpty()) {
                        $cards[] = $result->toArray();
                    }
                }
            }

            return $this->message(
                text: $cards === [] ? __('Nothing happened that day and nothing is waiting.') : null,
                period: Display::date($context->from),
                cards: $cards,
            );
        }

        $usesPeriod = $this->questions->usesPeriod($question);
        [$from, $until] = self::period((string) ($choices['period'] ?? 'month'), $choices['from'] ?? null, $choices['until'] ?? null);
        $context = FactContext::make($user, [
            'from' => $from,
            'until' => $until,
            'branch' => $branch,
            'currency' => $currency,
            'options' => $question->options,
        ]);

        $result = $this->facts->build((string) $question->fact, $context);

        return $this->message(
            period: $usesPeriod ? ($from === $until ? Display::date($from) : Display::date($from).' – '.Display::date($until)) : null,
            cards: $result === null ? [] : [$result->toArray()],
        );
    }

    /**
     * Typed or scanned text: a code first (when it looks like one), then the closest questions.
     *
     * @return array<string, mixed>
     */
    public function lookup(User $user, string $text, bool $phone = false): array
    {
        $text = mb_substr(trim((string) DigitConverter::toWestern($text)), 0, 200);

        if ($text === '') {
            return $this->message(text: __('Type a code or a few words.'));
        }

        $visible = array_values(array_filter($this->questions->visibleTo($user), fn (QuickQuestion $q) => ! $q->isPrompt()));
        $looksLikeCode = ! preg_match('/\s/u', $text) && preg_match('/[0-9]/', $text);

        if (! $looksLikeCode && ($matches = $this->matcher->match($text, $this->questions->visibleTo($user))) !== []) {
            return $this->message(text: __('Did you mean:'), suggestions: $this->suggestions($matches));
        }

        if (! preg_match('/\s/u', $text) && ($cards = $this->codes->find($user, $text, $phone)) !== []) {
            return $this->message(cards: array_map(fn (FactResult $card) => $card->toArray(), $cards));
        }

        if ($looksLikeCode && ($matches = $this->matcher->match($text, $this->questions->visibleTo($user))) !== []) {
            return $this->message(text: __('Nothing has the code :code. Did you mean:', ['code' => $text]), suggestions: $this->suggestions($matches));
        }

        return $this->message(
            text: $looksLikeCode ? __('Nothing has the code :code. I can answer these:', ['code' => $text]) : __('I did not understand. I can answer these:'),
            suggestions: $this->suggestions($visible),
        );
    }

    /**
     * A period preset → from / until (YYYY-MM-DD, business time). The week starts on Saturday.
     *
     * @return array{0: string, 1: string}
     */
    public static function period(string $preset, ?string $from = null, ?string $until = null): array
    {
        $today = now(config('orthotrack.timezone'))->startOfDay();

        return match ($preset) {
            'today' => [$today->toDateString(), $today->toDateString()],
            'yesterday' => [$today->copy()->subDay()->toDateString(), $today->copy()->subDay()->toDateString()],
            'week' => [$today->copy()->startOfWeek(Carbon::SATURDAY)->toDateString(), $today->toDateString()],
            'custom' => [self::validDate($from) ?? $today->toDateString(), self::validDate($until) ?? self::validDate($from) ?? $today->toDateString()],
            default => [$today->copy()->startOfMonth()->toDateString(), $today->toDateString()],
        };
    }

    private static function validDate(?string $date): ?string
    {
        return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) !== false ? $date : null;
    }

    /**
     * @param  list<QuickQuestion>  $questions
     * @return list<array{key: string, label: string}>
     */
    private function suggestions(array $questions): array
    {
        return array_map(fn (QuickQuestion $q) => ['key' => $q->key, 'label' => $q->label], $questions);
    }

    /**
     * @param  list<array<string, mixed>>  $cards
     * @param  list<array{key: string, label: string}>  $suggestions
     * @return array<string, mixed>
     */
    private function message(?string $text = null, ?string $period = null, array $cards = [], array $suggestions = [], bool $focus = false): array
    {
        return [
            'id' => uniqid('qa', true),
            'from' => 'bot',
            'text' => $text,
            'period' => $period,
            'cards' => $cards,
            'suggestions' => $suggestions,
            'focus' => $focus,
        ];
    }
}
